<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Lexical retrieval. On MySQL uses the InnoDB FULLTEXT index (ngram parser,
 * so Hebrew substrings/prefixed words match). Other drivers (sqlite in
 * tests) fall back to a LIKE scan with the same scoring idea.
 */
class KeywordSearch
{
    /**
     * @param  array<string, float>  $terms  normalized term => weight
     * @return array<int, float> product id => score, best first
     */
    public function search(array $terms, int $limit): array
    {
        if ($terms === []) {
            return [];
        }

        return DB::getDriverName() === 'mysql'
            ? $this->fulltext($terms, $limit)
            : $this->like($terms, $limit);
    }

    private function fulltext(array $terms, int $limit): array
    {
        // One MATCH per term so each term's weight applies; a quoted phrase
        // in BOOLEAN MODE with the ngram parser = "contains this substring".
        $selects = [];
        $bindings = [];
        foreach ($terms as $term => $weight) {
            $selects[] = sprintf('(MATCH(search_text) AGAINST (? IN BOOLEAN MODE) * %F)', $weight);
            $bindings[] = $this->booleanTerm($term);
        }
        $any = implode(' ', array_map(fn ($t) => '('.$this->booleanTerm($t).')', array_keys($terms)));

        $rows = DB::select(
            'SELECT id, ('.implode(' + ', $selects).') AS score
               FROM products
              WHERE MATCH(search_text) AGAINST (? IN BOOLEAN MODE)
              ORDER BY score DESC
              LIMIT '.(int) $limit,
            [...$bindings, $any],
        );

        $scores = [];
        foreach ($rows as $row) {
            $scores[(int) $row->id] = (float) $row->score;
        }

        return $scores;
    }

    private function like(array $terms, int $limit): array
    {
        $scores = [];
        foreach (Product::query()->get(['id', 'search_text']) as $p) {
            $score = 0.0;
            foreach ($terms as $term => $weight) {
                $words = explode(' ', $term);
                if (count(array_filter($words, fn ($w) => str_contains($p->search_text, $w))) === count($words)) {
                    $score += $weight;
                }
            }
            if ($score > 0) {
                $scores[$p->id] = $score;
            }
        }
        arsort($scores);

        return array_slice($scores, 0, $limit, true);
    }

    /**
     * Multi-word terms require every word (any order): "apple סמארטפון"
     * => +"apple" +"סמארטפון". Each quoted word with the ngram parser means
     * "contains this substring".
     */
    private function booleanTerm(string $term): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', str_replace(['"', '+', '-', '<', '>', '(', ')', '~', '*', '@'], ' ', $term)));
        $words = explode(' ', $clean);

        return count($words) === 1
            ? '"'.$clean.'"'
            : implode(' ', array_map(fn ($w) => '+"'.$w.'"', $words));
    }
}
