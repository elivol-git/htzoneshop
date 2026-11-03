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

        return $this->dropSubstringOnlyHits($scores, $terms);
    }

    /**
     * The ngram parser matches substrings, which is right for Hebrew (glued
     * prefixes) but wrong for English: "ski" matches "skincare" and "Moleskine".
     * Keep a hit only if at least one term matches strictly (see wordMatches).
     *
     * @param  array<int, float>  $scores
     * @param  array<string, float>  $terms
     */
    private function dropSubstringOnlyHits(array $scores, array $terms): array
    {
        if ($scores === []) {
            return [];
        }
        $texts = Product::query()->whereIn('id', array_keys($scores))->pluck('search_text', 'id');

        return array_filter($scores, function ($_, $id) use ($texts, $terms) {
            foreach (array_keys($terms) as $term) {
                if ($this->termMatches((string) ($texts[$id] ?? ''), $term)) {
                    return true;
                }
            }

            return false;
        }, ARRAY_FILTER_USE_BOTH);
    }

    private function termMatches(string $text, string $term): bool
    {
        foreach (explode(' ', $term) as $word) {
            if (! $this->wordMatches($text, $word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Latin words must match a whole word (optional plural: phone/phones).
     * Hebrew words and bare numbers stay substring matches: Hebrew glues
     * prefixes onto words, and "16" should still hit "16GB".
     */
    private function wordMatches(string $text, string $word): bool
    {
        if (preg_match('/^[a-z][a-z0-9]*$/', $word) !== 1) {
            return str_contains($text, $word);
        }

        return preg_match('/(?<![a-z0-9])'.preg_quote($word, '/').'(?:e?s)?(?![a-z0-9])/', $text) === 1;
    }

    private function like(array $terms, int $limit): array
    {
        $scores = [];
        foreach (Product::query()->get(['id', 'search_text']) as $p) {
            $score = 0.0;
            foreach ($terms as $term => $weight) {
                if ($this->termMatches($p->search_text, $term)) {
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
