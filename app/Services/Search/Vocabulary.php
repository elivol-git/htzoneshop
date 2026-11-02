<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/** Set of normalized tokens present in the catalog (for prefix stripping). */
class Vocabulary
{
    private const CACHE_KEY = 'search:vocabulary:v1';

    /** @return array<string, true> */
    public static function load(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $vocab = [];
            Product::query()->select(['id', 'search_text'])->lazyById(500)->each(function ($p) use (&$vocab) {
                foreach (explode(' ', $p->search_text) as $token) {
                    $vocab[$token] = true;
                }
            });

            return $vocab;
        });
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
