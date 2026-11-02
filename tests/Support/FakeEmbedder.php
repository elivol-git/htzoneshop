<?php

namespace Tests\Support;

use App\Services\Search\Embedder;
use App\Services\Search\EmbeddingUnavailable;
use App\Services\Search\TextNormalizer;
use App\Services\Search\VectorCodec;

/** Deterministic bag-of-words "embedding" (hashed tokens) for tests. */
class FakeEmbedder implements Embedder
{
    public int $queryCalls = 0;

    public bool $down = false;

    public function model(): string
    {
        return config('services.huggingface.model');
    }

    public function embedQuery(string $query): array
    {
        $this->queryCalls++;
        if ($this->down) {
            throw new EmbeddingUnavailable('fake outage');
        }

        return $this->vector($query);
    }

    public function embedPassages(array $passages): array
    {
        return array_map(fn ($p) => $this->vector($p), $passages);
    }

    private function vector(string $text): array
    {
        $v = array_fill(0, 64, 0.0);
        foreach ((new TextNormalizer)->tokens($text) as $t) {
            $v[crc32($t) % 64] += 1.0;
        }

        return VectorCodec::normalize($v);
    }
}
