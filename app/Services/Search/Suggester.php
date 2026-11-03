<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/**
 * Autocomplete: suggests categories, brand/synonym terms and product names
 * whose words start with what the user has typed, plus guessed variants:
 *  - synonym/transliteration siblings ("iph" also offers "אייפון"),
 *  - text typed on the wrong keyboard layout ("akuh" -> Hebrew),
 *  - one-letter typos ("שוב אבק" -> "שואב אבק"), used only when nothing else matched.
 * Pure string matching over a cached in-memory index: it never touches the
 * embedding model, so it stays fast.
 */
class Suggester
{
    private const CACHE_KEY = 'search:suggest-index:v2';

    private const MIN_CHARS = 2;

    /** Lower sorts first: categories and known terms read better than long product names. */
    private const TYPE_PRIORITY = ['category' => 0, 'term' => 1, 'brand' => 1, 'product' => 2];

    private const MAX_PRODUCTS = 4;

    /** Standard Israeli keyboard: the Latin key and the Hebrew letter on it. */
    private const KEYBOARD = [
        'q' => '/', 'w' => "'", 'e' => 'ק', 'r' => 'ר', 't' => 'א', 'y' => 'ט', 'u' => 'ו', 'i' => 'ן', 'o' => 'ם', 'p' => 'פ',
        'a' => 'ש', 's' => 'ד', 'd' => 'ג', 'f' => 'כ', 'g' => 'ע', 'h' => 'י', 'j' => 'ח', 'k' => 'ל', 'l' => 'ך', ';' => 'ף',
        'z' => 'ז', 'x' => 'ס', 'c' => 'ב', 'v' => 'ה', 'b' => 'נ', 'n' => 'מ', 'm' => 'צ', ',' => 'ת', '.' => 'ץ',
    ];

    /** @var array<string, list<int>> normalized synonym term => group indexes */
    private array $termGroups = [];

    public function __construct(private TextNormalizer $normalizer, private array $synonymGroups = [])
    {
        foreach ($synonymGroups as $gi => $group) {
            foreach ($group as $term) {
                $this->termGroups[$this->normalizer->normalize($term)][] = $gi;
            }
        }
    }

    /** @return list<array{text: string, type: string}> */
    public function suggest(string $query, int $limit = 6): array
    {
        $words = $this->normalizer->tokens($query);
        if ($words === [] || mb_strlen(implode('', $words)) < self::MIN_CHARS) {
            return [];
        }

        $ranked = $this->rank($words, 0);
        if ($ranked === [] && ($flipped = $this->flipKeyboardLayout($query)) !== null) {
            $ranked = $this->rank($this->normalizer->tokens($flipped), 0);
        }
        if ($ranked === []) {
            $ranked = $this->rank($words, 1);
        }

        return $this->pick($ranked, $limit);
    }

    /**
     * @param  list<array>  $ranked
     * @return list<array{text: string, type: string}>
     */
    private function pick(array $ranked, int $limit): array
    {
        $out = [];
        $seen = [];
        $products = 0;
        $push = function (string $text, string $type) use (&$out, &$seen, $limit): bool {
            $key = $this->normalizer->normalize($text);
            if (isset($seen[$key]) || count($out) >= $limit) {
                return false;
            }
            $seen[$key] = true;
            $out[] = ['text' => $text, 'type' => $type];

            return true;
        };

        foreach ($ranked as $entry) {
            if ($entry['type'] === 'product' && $products >= self::MAX_PRODUCTS) {
                continue;
            }
            if ($push($entry['text'], $entry['type']) && $entry['type'] === 'product') {
                $products++;
            }
            // Variants: every other spelling in the same synonym group.
            foreach ($this->termGroups[$entry['key']] ?? [] as $gi) {
                foreach ($this->synonymGroups[$gi] as $sibling) {
                    if ($this->suggestable($sibling)) {
                        $push($sibling, 'term');
                    }
                }
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** @return list<array> matching entries, best first */
    private function rank(array $queryWords, int $maxTypos): array
    {
        $matches = [];
        foreach ($this->index() as $entry) {
            if (($position = $this->matchPosition($queryWords, $entry['words'], $maxTypos)) !== null) {
                $matches[] = [$position, self::TYPE_PRIORITY[$entry['type']], mb_strlen($entry['text']), $entry];
            }
        }
        usort($matches, fn ($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_column($matches, 3);
    }

    /**
     * Every query word must be the start of some entry word (Hebrew prefix
     * letters are ignored: "כלב" matches "לכלב"). Returns where the first query
     * word matched (earlier = better), or null when the entry does not match.
     * With $maxTypos > 0 a word may also match with that many wrong letters.
     *
     * @param  list<string>  $queryWords
     * @param  list<list<string>>  $entryWords  per word: the word and its prefix-stripped variants
     */
    private function matchPosition(array $queryWords, array $entryWords, int $maxTypos): ?int
    {
        $first = null;
        foreach ($queryWords as $qw) {
            $found = null;
            foreach ($entryWords as $pos => $variants) {
                foreach ($variants as $variant) {
                    if ($this->startsWith($variant, $qw, $maxTypos)) {
                        $found = $pos;
                        break 2;
                    }
                }
            }
            if ($found === null) {
                return null;
            }
            $first ??= $found;
        }

        return $first;
    }

    private function startsWith(string $word, string $prefix, int $maxTypos): bool
    {
        if (str_starts_with($word, $prefix)) {
            return true;
        }
        // Typos only for words long enough that one wrong letter is not noise.
        if ($maxTypos === 0 || mb_strlen($prefix) < 3) {
            return false;
        }

        return $this->editDistance(mb_substr($word, 0, mb_strlen($prefix)), $prefix) <= $maxTypos;
    }

    /** Levenshtein on characters (PHP's built-in one counts bytes, wrong for Hebrew). */
    private function editDistance(string $a, string $b): int
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        $prev = range(0, count($b));
        foreach ($a as $i => $ca) {
            $cur = [$i + 1];
            foreach ($b as $j => $cb) {
                $cur[] = min($prev[$j + 1] + 1, $cur[$j] + 1, $prev[$j] + ($ca === $cb ? 0 : 1));
            }
            $prev = $cur;
        }

        return $prev[count($b)];
    }

    /** "akuh" typed on an English layout -> the Hebrew letters on those keys, and back. Null when not applicable. */
    private function flipKeyboardLayout(string $query): ?string
    {
        $query = mb_strtolower($query);
        if (preg_match('/^[a-z;,.\' ]+$/', $query)) {
            return strtr($query, self::KEYBOARD);
        }
        if (preg_match('/^[\x{05D0}-\x{05EA}\/\' ]+$/u', $query)) {
            return strtr($query, array_flip(self::KEYBOARD));
        }

        return null;
    }

    /** "apple סמארטפון" style helper phrases mix scripts: not worth suggesting. */
    private function suggestable(string $term): bool
    {
        return ! (preg_match('/\p{Hebrew}/u', $term) && preg_match('/[a-z]/i', $term));
    }

    /** @return list<array{text: string, key: string, type: string, words: list<list<string>>}> */
    private function index(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $entries = [];
            $add = function (string $text, string $type) use (&$entries) {
                $text = trim($text);
                $key = $this->normalizer->normalize($text);
                if ($key === '' || isset($entries[$key])) {
                    return;
                }
                $entries[$key] = [
                    'text' => $text,
                    'key' => $key,
                    'type' => $type,
                    'words' => array_map(
                        fn ($w) => [$w, ...$this->normalizer->prefixVariants($w)],
                        explode(' ', $key),
                    ),
                ];
            };

            // Categories, brands and synonym terms first: on equal text they win over a product name.
            Product::query()->distinct()->pluck('subcategory')->each(fn ($c) => $add((string) $c, 'category'));
            Product::query()->distinct()->pluck('category')->each(fn ($c) => $add((string) $c, 'category'));
            Product::query()->whereNotNull('brand')->distinct()->pluck('brand')->each(fn ($b) => $add((string) $b, 'brand'));
            foreach ($this->synonymGroups as $group) {
                foreach ($group as $term) {
                    if ($this->suggestable($term)) {
                        $add($term, 'term');
                    }
                }
            }
            Product::query()->pluck('name')->each(fn ($n) => $add((string) $n, 'product'));

            return array_values($entries);
        });
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
