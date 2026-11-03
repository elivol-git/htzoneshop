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
        \App\Services\Search\Suggester::flush();
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

    public function test_english_terms_match_whole_words_not_substrings(): void
    {
        // "ski" must not hit "skincare" / "Moleskine" (ngram substring matching).
        foreach (['skincare set' => 'P9001', 'moleskine notebook' => 'P9002', 'ski gloves' => 'P9003'] as $text => $id) {
            Product::forceCreate([
                'external_id' => $id, 'name' => $text, 'category' => 'x', 'subcategory' => 'x',
                'price' => 100, 'search_text' => $text,
            ]);
        }
        $keyword = app(\App\Services\Search\KeywordSearch::class);

        $ids = Product::whereIn('id', array_keys($keyword->search(['ski' => 1.0], 10)))->pluck('external_id')->all();
        $this->assertSame(['P9003'], $ids);

        // Plurals still match; Hebrew stays substring-based.
        $this->assertNotEmpty($keyword->search(['glove' => 1.0], 10));
        $this->assertNotEmpty($keyword->search(['אבק' => 1.0], 10));
    }

    private function suggest(string $q): array
    {
        return array_column($this->getJson('/api/suggest?q='.urlencode($q))->assertOk()->json('suggestions'), 'text');
    }

    public function test_suggest_completes_word_prefixes(): void
    {
        $items = $this->getJson('/api/suggest?q='.urlencode('שואב'))->json('suggestions');

        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            // Synonym variants ("vacuum") are allowed; categories and products must start with the typed word.
            if ($item['type'] !== 'term') {
                $this->assertStringContainsString('שוא', $item['text']);
            }
        }
        $texts = array_column($items, 'text');
        $this->assertLessThanOrEqual(6, count($texts));
        $this->assertSame([], $this->suggest('ש'), 'one character is too short to suggest');
        $this->assertSame([], $this->suggest('zzzzqq'));
    }

    public function test_suggest_matches_every_typed_word_and_ignores_hebrew_prefix_letters(): void
    {
        $this->assertContains('שואב אבק אנכי אלחוטי דייסון עם חיישן לייזר לגילוי אבק', $this->suggest('שואב אבק'));
        $this->assertNotContains('מטהג אוויר חם Air Fryer XL', $this->suggest('שואב אבק'));
        // "לגילוי" starts with the prefix letter ל; typing the bare word still finds it.
        $this->assertContains('שואב אבק אנכי אלחוטי דייסון עם חיישן לייזר לגילוי אבק', $this->suggest('גילוי'));
    }

    public function test_suggest_offers_synonym_variants(): void
    {
        $texts = $this->suggest('iph');

        $this->assertContains('iphone', array_map('strtolower', $texts));
        $this->assertContains('אייפון', $texts, 'typing the English spelling also offers the Hebrew one');
    }

    public function test_suggest_fixes_wrong_keyboard_layout(): void
    {
        // "שואב" typed with the keyboard still on English is "autc".
        $this->assertSame($this->suggest('שואב'), $this->suggest('autc'));
    }

    public function test_suggest_tolerates_one_letter_typo_only_as_last_resort(): void
    {
        $this->assertContains('שואב אבק אנכי אלחוטי דייסון עם חיישן לייזר לגילוי אבק', $this->suggest('שוב אבק'));
        $this->assertSame([], $this->suggest('בלנדרר'.'x'), 'two letters off is not a typo');
    }

    public function test_suggest_validates_input(): void
    {
        $this->getJson('/api/suggest')->assertUnprocessable();
        $this->getJson('/api/suggest?q='.str_repeat('a', 101))->assertUnprocessable();
    }

    public function test_app_trusts_proxy_headers_for_scheme_and_client_ip(): void
    {
        // Behind Caddy: rate limiting must see the visitor, not the proxy, and URLs must be https.
        \Illuminate\Support\Facades\Route::get('/_probe', fn (\Illuminate\Http\Request $r) => ['secure' => $r->secure(), 'ip' => $r->ip()]);

        $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9'])
            ->getJson('/_probe')
            ->assertExactJson(['secure' => true, 'ip' => '203.0.113.9']);
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

    public function test_circuit_breaker_skips_embedding_calls_after_failure(): void
    {
        $this->embedder->down = true;
        $this->getJson('/api/search?q=blender')->assertJson(['degraded' => true]);
        $this->getJson('/api/search?q=mixer')->assertJson(['degraded' => true]);
        $this->assertSame(1, $this->embedder->queryCalls);
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

    public function test_self_hosted_endpoint_works_without_token(): void
    {
        Http::fake(['tei/*' => Http::response([[3, 4]])]);
        $tei = new HuggingFaceEmbedder(null, 'intfloat/multilingual-e5-large', 'http://tei/embed', 'query: ');

        $this->assertEqualsWithDelta([0.6, 0.8], $tei->embedQuery('אייפון'), 1e-6);
        Http::assertSent(fn ($r) => $r->url() === 'http://tei/embed'
            && $r['inputs'] === ['query: אייפון'] && ! $r->hasHeader('Authorization'));
    }

    public function test_hosted_hugging_face_still_requires_token(): void
    {
        Http::fake();
        $hf = new HuggingFaceEmbedder('', 'm', 'https://router.huggingface.co/hf-inference/models/{model}/pipeline/feature-extraction');
        $this->expectException(EmbeddingUnavailable::class);
        $hf->embedQuery('x');
    }

    public function test_hugging_face_errors_become_embedding_unavailable(): void
    {
        Http::fake(['*' => Http::response('loading', 503)]);
        $hf = new HuggingFaceEmbedder('tok', 'm', 'https://x/{model}');
        $this->expectException(EmbeddingUnavailable::class);
        $hf->embedQuery('x');
    }
}
