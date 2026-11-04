<?php

namespace App\Services\Search;

/**
 * Turns a raw user query into weighted keyword terms:
 * content tokens, Hebrew prefix-stripped variants that exist in the catalog
 * vocabulary, and members of matching synonym groups (Hebrew <-> English).
 */
class QueryExpander
{
    /** @var array<string, list<int>> normalized synonym phrase => group indexes */
    private array $synonymIndex = [];

    /** @var list<list<string>> */
    private array $groups = [];

    /**
     * @param  list<list<string>>  $synonymGroups
     * @param  array<string, true>  $vocabulary  normalized tokens present in the catalog
     */
    public function __construct(
        private TextNormalizer $normalizer,
        array $synonymGroups,
        private array $vocabulary = [],
        private array $misspellings = [],
    ) {
        foreach ($synonymGroups as $gi => $group) {
            $this->groups[$gi] = array_map(fn ($t) => $this->normalizer->normalize($t), $group);
            foreach ($this->groups[$gi] as $phrase) {
                $this->synonymIndex[$phrase][] = $gi;
            }
        }
    }

    /**
     * @return array{tokens: list<string>, terms: array<string, float>}
     *         terms: normalized term/phrase => weight (1.0 original, lower for expansions)
     */
    public function expand(string $query): array
    {
        $fix = fn (array $ts) => array_map(fn ($t) => $this->misspellings[$t] ?? $t, $ts);
        $tokens = $fix($this->normalizer->contentTokens($query));
        $allTokens = $fix($this->normalizer->tokens($query));
        $terms = [];

        $add = function (string $term, float $weight) use (&$terms) {
            if ($term === '' || (mb_strlen($term) < 2 && ! ctype_digit($term))) {
                return;
            }
            $terms[$term] = max($terms[$term] ?? 0, $weight);
        };

        foreach ($tokens as $token) {
            // Bare numbers ("16") are weak evidence: they hit "16GB", "16 inch"...
            $add($token, ctype_digit($token) ? 0.3 : 1.0);
            foreach ($this->normalizer->prefixVariants($token) as $variant) {
                if (isset($this->vocabulary[$variant]) || isset($this->synonymIndex[$variant])) {
                    $add($variant, 0.9);
                }
            }
        }

        // Synonyms: check unigrams/bigrams/trigrams of the full token stream
        // (including prefix-stripped forms) against the synonym index.
        $candidates = [];
        $n = count($allTokens);
        for ($i = 0; $i < $n; $i++) {
            for ($len = 1; $len <= 3 && $i + $len <= $n; $len++) {
                $phrase = implode(' ', array_slice($allTokens, $i, $len));
                $candidates[] = $phrase;
                foreach ($this->normalizer->prefixVariants($phrase) as $v) {
                    $candidates[] = $v;
                }
            }
        }
        foreach (array_unique($candidates) as $phrase) {
            foreach ($this->synonymIndex[$phrase] ?? [] as $gi) {
                foreach ($this->groups[$gi] as $member) {
                    $add($member, $member === $phrase ? 1.0 : 0.7);
                }
            }
        }

        return ['tokens' => $tokens, 'terms' => $terms];
    }
}
