<?php

namespace App\Services\Search;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Sentence embeddings via the Hugging Face Inference API (feature-extraction).
 */
class HuggingFaceEmbedder implements Embedder
{
    public function __construct(
        private ?string $token,
        private string $model,
        private string $endpoint,
        private string $queryPrefix = '',
        private string $passagePrefix = '',
        private float $timeout = 6.0,
    ) {}

    public static function fromConfig(): self
    {
        $c = config('services.huggingface');

        return new self($c['token'], $c['model'], $c['endpoint'], $c['query_prefix'], $c['passage_prefix'], $c['timeout']);
    }

    public function model(): string
    {
        return $this->model;
    }

    public function embedQuery(string $query): array
    {
        return $this->request([$this->queryPrefix.$query])[0];
    }

    public function embedPassages(array $passages): array
    {
        return $this->request(array_map(fn ($p) => $this->passagePrefix.$p, $passages), timeout: 60);
    }

    /** @return list<list<float>> */
    private function request(array $inputs, ?float $timeout = null): array
    {
        if (blank($this->token)) {
            throw new EmbeddingUnavailable('HF_TOKEN is not configured');
        }

        $url = str_replace('{model}', $this->model, $this->endpoint);

        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->timeout($timeout ?? $this->timeout)
                ->connectTimeout(3)
                ->retry(2, 300, fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504])), throw: false)
                ->post($url, ['inputs' => $inputs, 'normalize' => true, 'truncate' => true]);
        } catch (ConnectionException $e) {
            throw new EmbeddingUnavailable('Hugging Face unreachable: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new EmbeddingUnavailable("Hugging Face error {$response->status()}: ".mb_substr($response->body(), 0, 300));
        }

        $data = $response->json();
        if (! is_array($data) || count($data) !== count($inputs)) {
            throw new EmbeddingUnavailable('Unexpected Hugging Face response shape');
        }

        return array_map(fn ($item) => VectorCodec::normalize($this->pool($item)), $data);
    }

    /** Accepts a pooled vector, or token-level vectors (mean-pooled here). */
    private function pool(array $item): array
    {
        if (isset($item[0]) && is_array($item[0])) {
            $dims = count($item[0]);
            $sum = array_fill(0, $dims, 0.0);
            foreach ($item as $tokenVector) {
                foreach ($tokenVector as $i => $v) {
                    $sum[$i] += $v;
                }
            }

            return array_map(fn ($v) => $v / count($item), $sum);
        }

        return array_map('floatval', $item);
    }
}
