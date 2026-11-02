<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hybrid search: lexical (FULLTEXT + synonyms) and semantic (multilingual
 * embeddings) rankings fused with Reciprocal Rank Fusion, plus a boost for
 * products whose name contains every query word (exact-name lookups).
 */
class SearchService
{
    public function __construct(
        private TextNormalizer $normalizer,
        private QueryExpander $expander,
        private KeywordSearch $keyword,
        private VectorIndex $vectors,
        private QueryEmbeddingCache $queryEmbeddings,
        private array $config,
    ) {}

    public function search(string $query, ?int $limit = null): SearchResult
    {
        $limit ??= $this->config['max_results'];
        $normalized = $this->normalizer->normalize($query);
        if ($normalized === '') {
            return new SearchResult([], false);
        }

        $expanded = $this->expander->expand($query);
        $keywordScores = $this->keyword->search($expanded['terms'], $this->config['keyword_candidates']);

        $degraded = false;
        $semanticScores = [];
        try {
            if (! $this->vectors->isEmpty()) {
                $semanticScores = $this->vectors->search(
                    $this->queryEmbeddings->get($normalized),
                    $this->config['semantic_candidates'],
                );
            } else {
                $degraded = true;
            }
        } catch (Throwable $e) {
            // Semantic leg is best-effort: keep serving keyword results.
            Log::warning('Semantic search unavailable', ['error' => $e->getMessage()]);
            $degraded = true;
        }

        $semanticScores = $this->filterSemantic($semanticScores);
        $fused = $this->fuse($keywordScores, $semanticScores);
        $fused = $this->boostExactNames($fused, $expanded['tokens']);

        uasort($fused, fn ($a, $b) => $b['score'] <=> $a['score']);
        $hits = array_slice(array_values($fused), 0, $limit);

        return new SearchResult($hits, $degraded, [
            'terms' => $expanded['terms'],
            'keyword' => array_slice($keywordScores, 0, 10, true),
            'semantic' => array_slice($semanticScores, 0, 10, true),
        ]);
    }

    /** Drop weak semantic matches (absolute floor + window below the best). */
    private function filterSemantic(array $scores): array
    {
        if ($scores === []) {
            return [];
        }
        $best = reset($scores);
        $floor = max($this->config['semantic_min_score'], $best - $this->config['semantic_relative_window']);

        return array_filter($scores, fn ($s) => $s >= $floor);
    }

    /** @return array<int, array{id:int, score:float, sources:list<string>}> */
    private function fuse(array $keywordScores, array $semanticScores): array
    {
        $k = $this->config['rrf_k'];
        $fused = [];
        $rank = 0;
        foreach ($keywordScores as $id => $_) {
            $fused[$id] ??= ['id' => $id, 'score' => 0.0, 'sources' => []];
            $fused[$id]['score'] += $this->config['keyword_weight'] / ($k + ++$rank);
            $fused[$id]['sources'][] = 'keyword';
        }
        $rank = 0;
        foreach ($semanticScores as $id => $_) {
            $fused[$id] ??= ['id' => $id, 'score' => 0.0, 'sources' => []];
            $fused[$id]['score'] += $this->config['semantic_weight'] / ($k + ++$rank);
            $fused[$id]['sources'][] = 'semantic';
        }

        return $fused;
    }

    /** Products whose name/brand contains all query words jump to the top. */
    private function boostExactNames(array $fused, array $tokens): array
    {
        // Numbers alone ("16") must not trigger a name match.
        $words = array_values(array_filter($tokens, fn ($t) => ! ctype_digit($t)));
        if ($fused === [] || $words === []) {
            return $fused;
        }
        $tokens = $words;
        $names = Product::query()->whereIn('id', array_keys($fused))->get(['id', 'name', 'brand']);
        foreach ($names as $p) {
            $haystack = ' '.$this->normalizer->normalize($p->name.' '.$p->brand).' ';
            $matched = count(array_filter($tokens, fn ($t) => str_contains($haystack, $t)));
            if ($matched === count($tokens)) {
                $fused[$p->id]['score'] += 1.0 / $this->config['rrf_k'];
            } elseif ($matched > 0) {
                $fused[$p->id]['score'] += 0.25 * $matched / count($tokens) / $this->config['rrf_k'];
            }
        }

        return $fused;
    }
}
