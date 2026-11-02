<?php

namespace App\Services\Search;

final class SearchResult
{
    /** @param  list<array{id:int, score:float, sources:list<string>}>  $hits */
    public function __construct(
        public readonly array $hits,
        public readonly bool $degraded,
        public readonly array $debug = [],
    ) {}
}
