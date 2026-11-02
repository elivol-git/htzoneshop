<?php

namespace App\Services\Search;

interface Embedder
{
    /** @return list<float> L2-normalized vector */
    public function embedQuery(string $query): array;

    /**
     * @param  list<string>  $passages
     * @return list<list<float>> L2-normalized vectors, same order
     */
    public function embedPassages(array $passages): array;

    public function model(): string;
}
