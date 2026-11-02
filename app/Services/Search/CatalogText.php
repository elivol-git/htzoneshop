<?php

namespace App\Services\Search;

/** Builds the text representations of a product used by both retrievers. */
class CatalogText
{
    public function __construct(private TextNormalizer $normalizer) {}

    /**
     * English keyword embedded in the placeholder image URL
     * (".../png?text=robot%20vacuum") — free English signal for Hebrew rows.
     */
    public static function imageKeyword(?string $url): string
    {
        if (! $url || ! ($query = parse_url($url, PHP_URL_QUERY))) {
            return '';
        }
        parse_str($query, $params);

        return trim((string) ($params['text'] ?? ''));
    }

    public function searchText(array $p): string
    {
        return $this->normalizer->normalize(implode(' ', array_filter([
            $p['name'] ?? '', $p['brand'] ?? '', $p['category'] ?? '', $p['subcategory'] ?? '',
            self::imageKeyword($p['image_url'] ?? null),
            $p['attributes'] ?? '', $p['description'] ?? '',
        ])));
    }

    /** Text sent to the embedding model (kept natural, not normalized). */
    public function passage(array $p): string
    {
        return implode('. ', array_filter([
            $p['name'] ?? '',
            $p['brand'] ?? '',
            trim(($p['category'] ?? '').' > '.($p['subcategory'] ?? ''), ' >'),
            self::imageKeyword($p['image_url'] ?? null),
            $p['description'] ?? '',
            $p['attributes'] ?? '',
        ]));
    }
}
