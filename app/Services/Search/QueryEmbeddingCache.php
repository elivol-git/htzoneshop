<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Persists query embeddings so repeated searches skip the HF round-trip.
 * Includes a simple circuit breaker: after a failure, new queries skip HF
 * for BREAKER_SECONDS instead of each paying the timeout.
 */
class QueryEmbeddingCache
{
    private const BREAKER_KEY = 'search:hf-breaker';

    private const BREAKER_SECONDS = 60;

    public function __construct(private Embedder $embedder) {}

    /** @return list<float> */
    public function get(string $normalizedQuery): array
    {
        $model = $this->embedder->model();
        $hash = hash('sha256', $model.'|'.$normalizedQuery);

        $row = DB::table('query_embeddings')->where('query_hash', $hash)->first();
        if ($row) {
            DB::table('query_embeddings')->where('query_hash', $hash)->increment('hits');

            return VectorCodec::unpack($row->embedding);
        }

        if (Cache::has(self::BREAKER_KEY)) {
            throw new EmbeddingUnavailable('Embedding service recently failed; skipping');
        }

        try {
            $vector = $this->embedder->embedQuery($normalizedQuery);
        } catch (EmbeddingUnavailable $e) {
            Cache::put(self::BREAKER_KEY, true, self::BREAKER_SECONDS);
            throw $e;
        }

        DB::table('query_embeddings')->insertOrIgnore([
            'query_hash' => $hash,
            'model' => $model,
            'query' => mb_substr($normalizedQuery, 0, 255),
            'embedding' => VectorCodec::pack($vector),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $vector;
    }
}
