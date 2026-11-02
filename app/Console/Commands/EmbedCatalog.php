<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Search\CatalogText;
use App\Services\Search\Embedder;
use App\Services\Search\VectorCodec;
use App\Services\Search\VectorIndex;
use Illuminate\Console\Command;
use Throwable;

class EmbedCatalog extends Command
{
    protected $signature = 'catalog:embed {--force : Re-embed products that already have a vector} {--batch=32}';

    protected $description = 'Compute product embeddings via Hugging Face and store them in the DB';

    public function handle(Embedder $embedder, CatalogText $text): int
    {
        $model = $embedder->model();
        $query = Product::query();
        if (! $this->option('force')) {
            $query->where(fn ($q) => $q->whereNull('embedding')->orWhere('embedding_model', '!=', $model)->orWhereNull('embedding_model'));
        }

        $total = (clone $query)->count();
        $this->info("Embedding {$total} products with {$model}");
        if ($total === 0) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $failed = 0;
        $query->select(['id', 'name', 'brand', 'category', 'subcategory', 'description', 'attributes', 'image_url'])
            ->chunkById((int) $this->option('batch'), function ($products) use ($embedder, $text, $model, $bar, &$failed) {
                try {
                    $vectors = $this->withRetry(fn () => $embedder->embedPassages(
                        $products->map(fn ($p) => $text->passage($p->toArray()))->all()
                    ));
                } catch (Throwable $e) {
                    $failed += $products->count();
                    $this->newLine();
                    $this->error('Batch failed: '.$e->getMessage());
                    $bar->advance($products->count());

                    return;
                }
                foreach ($products->values() as $i => $p) {
                    Product::whereKey($p->id)->update([
                        'embedding' => VectorCodec::pack($vectors[$i]),
                        'embedding_model' => $model,
                    ]);
                }
                $bar->advance($products->count());
            });

        $bar->finish();
        $this->newLine();
        VectorIndex::flush();

        if ($failed > 0) {
            $this->warn("{$failed} products failed; re-run the command to retry them.");

            return self::FAILURE;
        }
        $this->info('Done.');

        return self::SUCCESS;
    }

    private function withRetry(callable $fn, int $attempts = 4): mixed
    {
        for ($i = 1; ; $i++) {
            try {
                return $fn();
            } catch (Throwable $e) {
                if ($i >= $attempts) {
                    throw $e;
                }
                // Model cold start on HF can take ~20s.
                sleep(min(20, 2 ** $i));
            }
        }
    }
}
