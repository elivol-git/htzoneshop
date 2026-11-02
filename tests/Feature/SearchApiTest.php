<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Search\Embedder;
use App\Services\Search\EmbeddingUnavailable;
use App\Services\Search\HuggingFaceEmbedder;
use App\Services\Search\VectorIndex;
use App\Services\Search\Vocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeEmbedder;
use Tests\TestCase;

class SearchApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeEmbedder $embedder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->embedder = new FakeEmbedder;
        $this->app->instance(Embedder::class, $this->embedder);
        $this->artisan('catalog:import', ['path' => base_path('tests/fixtures/catalog.csv')])->assertSuccessful();
        $this->artisan('catalog:embed')->assertSuccessful();
        // Fake bag-of-words vectors are far less similar than real embeddings.
        config(['search.semantic_min_score' => 0.3, 'search.semantic_relative_window' => 0.5]);
    }

    protected function tearDown(): void
    {
        VectorIndex::flush();
        Vocabulary::flush();
        parent::tearDown();
    }

    public function test_import_creates_products_with_generated_prices(): void
    {
        $this->assertSame(8, Product::count());
        $p = Product::where('external_id', 'P0201')->first();
        $this->assertGreaterThanOrEqual(599, (float) $p->price);
        $this->assertLessThanOrEqual(3990, (float) $p->price);
        $this->assertStringContainsString('cordless vacuum', $p->search_text);
        $this->assertNotNull($p->embedding);

        // Re-import is idempotent and keeps the same deterministic price.
        $this->artisan('catalog:import', ['path' => base_path('tests/fixtures/catalog.csv')]);
        $this->assertSame(8, Product::count());
        $this->assertSame($p->price, Product::where('external_id', 'P0201')->first()->price);
    }

    public function test_hebrew_query_returns_matching_products_with_required_fields(): void
    {
        $res = $this->getJson('/api/search?q='.urlencode('שואב אבק'))->assertOk();

        $res->assertJsonStructure(['query', 'count', 'degraded', 'took_ms', 'results' => [['id', 'name', 'price', 'image_url']]]);
        $this->assertFalse($res->json('degraded'));
        $this->assertContains($res->json('results.0.id'), ['P0201', 'P0202']);
    }

    public function test_transliterated_brand_finds_apple_phones(): void
    {
        $results = $this->getJson('/api/search?q='.urlencode('אייפון'))->json('results');
        $ids = array_column($results, 'id');

        $this->assertSame('Apple', $results[0]['brand']);
        $this->assertContains('P0401', $ids);
        $this->assertContains('P0413', $ids);
        $this->assertNotContains('P0402', $ids, 'Samsung phone should not match "iPhone"');
    }

    public function test_english_query_matches_hebrew_catalog(): void
    {
        $res = $this->getJson('/api/search?q=blender')->assertOk();
        $this->assertSame('P0003', $res->json('results.0.id'));
    }

    public function test_falls_back_to_keyword_search_when_embeddings_are_down(): void
    {
        $this->embedder->down = true;
        $res = $this->getJson('/api/search?q='.urlencode('שואב אבק'))->assertOk();
        $this->assertTrue($res->json('degraded'));
        $this->assertGreaterThan(0, $res->json('count'));
    }

    public function test_query_embeddings_are_cached(): void
    {
        $this->getJson('/api/search?q=blender');
        $this->getJson('/api/search?q=blender');
        $this->assertSame(1, $this->embedder->queryCalls);
    }

    public function test_validation(): void
    {
        $this->getJson('/api/search')->assertStatus(422);
        $this->getJson('/api/search?q='.str_repeat('a', 201))->assertStatus(422);
    }

    public function test_unrelated_query_returns_empty_results(): void
    {
        $this->embedder->down = true; // keyword-only: nothing matches
        $this->getJson('/api/search?q=zzqqxx')->assertOk()->assertJson(['count' => 0, 'results' => []]);
    }

    public function test_home_page_renders_rtl(): void
    {
        $this->withoutVite();
        $this->get('/')->assertOk()->assertSee('dir="rtl"', false)->assertSee('מה בא לכם למצוא?');
    }

    public function test_hugging_face_client_parses_and_normalizes_vectors(): void
    {
        Http::fake(['router.huggingface.co/*' => Http::response([[3, 4], [0, 2]])]);
        $hf = new HuggingFaceEmbedder('tok', 'm/x', 'https://router.huggingface.co/hf-inference/models/{model}/pipeline/feature-extraction', 'query: ', 'passage: ');

        $this->assertEqualsWithDelta([[0.6, 0.8], [0.0, 1.0]], $hf->embedPassages(['a', 'b']), 1e-6);
        Http::assertSent(fn ($r) => $r['inputs'] === ['passage: a', 'passage: b'] && $r->hasHeader('Authorization', 'Bearer tok'));
    }

    public function test_hugging_face_errors_become_embedding_unavailable(): void
    {
        Http::fake(['*' => Http::response('loading', 503)]);
        $hf = new HuggingFaceEmbedder('tok', 'm', 'https://x/{model}');
        $this->expectException(EmbeddingUnavailable::class);
        $hf->embedQuery('x');
    }
}
