<?php

namespace App\Services\Search;

/**
 * Normalizes Hebrew/English text so the stored search_text and incoming
 * queries are tokenized identically.
 */
class TextNormalizer
{
    /** Hebrew one-letter prefixes (ו ה ב ל מ ש כ) that glue onto words. */
    private const HEBREW_PREFIXES = ['ו', 'ה', 'ב', 'ל', 'מ', 'ש', 'כ'];

    /** @param  list<string>  $stopwords */
    public function __construct(private array $stopwords = [])
    {
        $this->stopwords = array_flip($stopwords);
    }

    public function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        // Strip niqqud / cantillation marks.
        $text = preg_replace('/[\x{0591}-\x{05C7}]/u', '', $text);
        // Hebrew geresh/gershayim and typographic quotes -> ASCII.
        $text = strtr($text, ['׳' => "'", '״' => '"', '’' => "'", '‘' => "'", '“' => '"', '”' => '"']);
        // Keep letters, digits and apostrophes (ג'ל, צ'יפס); everything else is a separator.
        $text = preg_replace("/[^\p{L}\p{N}']+/u", ' ', $text);
        $text = preg_replace("/(^|\s)'+|'+(\s|$)/u", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** @return list<string> */
    public function tokens(string $text): array
    {
        $normalized = $this->normalize($text);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /** Tokens worth matching on: no stopwords, no single characters (except digits). */
    public function contentTokens(string $text): array
    {
        return array_values(array_filter(
            $this->tokens($text),
            fn (string $t) => ! isset($this->stopwords[$t]) && (mb_strlen($t) > 1 || ctype_digit($t)),
        ));
    }

    /**
     * Candidate base forms after removing up to two Hebrew prefix letters,
     * e.g. "מהספה" -> ["הספה", "ספה"].
     *
     * @return list<string>
     */
    public function prefixVariants(string $token): array
    {
        $variants = [];
        $current = $token;
        for ($i = 0; $i < 2; $i++) {
            $first = mb_substr($current, 0, 1);
            if (! in_array($first, self::HEBREW_PREFIXES, true) || mb_strlen($current) < 4) {
                break;
            }
            $current = mb_substr($current, 1);
            $variants[] = $current;
        }

        return $variants;
    }

    public function isHebrew(string $token): bool
    {
        return (bool) preg_match('/\p{Hebrew}/u', $token);
    }
}
