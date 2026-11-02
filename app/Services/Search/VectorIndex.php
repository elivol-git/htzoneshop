<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/**
 * In-memory brute-force cosine search over all product embeddings.
 * The catalog (~1k items x 1024 dims) is small enough that a full scan
 * costs a few ms; vectors are cached as packed binary to keep it cheap.
 */
class VectorIndex
{
    private const CACHE_KEY = 'search:vector-index:v1';

    /** @var array<int, list<float>>|null */
    private ?array $vectors = null;

    /** @return array<int, float> product id => similarity, best first */
    public function search(array $queryVector, int $limit): array
    {
        $scores = [];
        foreach ($this->vectors() as $id => $vector) {
            $scores[$id] = VectorCodec::dot($queryVector, $vector);
        }
        arsort($scores);

        return array_slice($scores, 0, $limit, true);
    }

    public function isEmpty(): bool
    {
        return $this->vectors() === [];
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<int, list<float>> */
    private function vectors(): array
    {
        if ($this->vectors !== null) {
            return $this->vectors;
        }

        $packed = Cache::rememberForever(self::CACHE_KEY, function () {
            $model = config('services.huggingface.model');

            return Product::query()
                ->whereNotNull('embedding')
                ->where('embedding_model', $model)
                ->pluck('embedding', 'id')
                ->map(fn ($bin) => base64_encode($bin))
                ->all();
        });

        return $this->vectors = array_map(
            fn ($b64) => VectorCodec::unpack(base64_decode($b64)),
            $packed,
        );
    }
}
