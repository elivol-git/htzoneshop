<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\DB;

/** Persists query embeddings so repeated searches skip the HF round-trip. */
class QueryEmbeddingCache
{
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

        $vector = $this->embedder->embedQuery($normalizedQuery);

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
