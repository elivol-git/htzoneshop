<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Search\CatalogText;
use App\Services\Search\Vocabulary;
use Illuminate\Console\Command;
use RuntimeException;

class ImportCatalog extends Command
{
    protected $signature = 'catalog:import {path? : CSV file (default: config catalog.csv_path)}';

    protected $description = 'Import/update products from the catalog CSV (idempotent upsert by id)';

    public function handle(CatalogText $text): int
    {
        $path = $this->argument('path') ?? config('catalog.csv_path');
        if (! is_readable($path)) {
            $this->error("CSV not readable: {$path}");

            return self::FAILURE;
        }

        $map = config('catalog.columns');
        $rows = [];
        $now = now();

        foreach ($this->readCsv($path) as $line => $raw) {
            $p = [];
            foreach ($map as $csvColumn => $column) {
                $value = isset($raw[$csvColumn]) ? trim($raw[$csvColumn]) : null;
                $p[$column] = $value === '' ? null : $value;
            }
            if (! $p['external_id'] || ! $p['name']) {
                $this->warn("Skipping line {$line}: missing id/name");

                continue;
            }
            $p['category'] ??= '';
            $p['subcategory'] ??= '';
            $p['price'] = is_numeric($p['price'] ?? null)
                ? round((float) $p['price'], 2)
                : $this->generatePrice($p['external_id'], $p['subcategory']);
            $p['search_text'] = $text->searchText($p);
            $p['created_at'] = $p['updated_at'] = $now;
            $rows[] = $p;
        }

        // Columns refreshed on re-import; embedding is kept (re-run catalog:embed --force if text changed).
        $update = ['name', 'brand', 'category', 'subcategory', 'description', 'attributes', 'image_url', 'price', 'search_text', 'updated_at'];
        foreach (array_chunk($rows, 200) as $chunk) {
            Product::query()->upsert($chunk, ['external_id'], $update);
        }

        Vocabulary::flush();
        $this->info('Imported '.count($rows).' products. Total in DB: '.Product::count());

        return self::SUCCESS;
    }

    /** @return \Generator<int, array<string, string>> */
    private function readCsv(string $path): \Generator
    {
        $fh = fopen($path, 'rb');
        $header = fgetcsv($fh, escape: '');
        if (! $header) {
            throw new RuntimeException('Empty CSV');
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]); // UTF-8 BOM
        $header = array_map('trim', $header);
        $line = 1;
        while (($cells = fgetcsv($fh, escape: '')) !== false) {
            $line++;
            if ($cells === [null]) {
                continue;
            }
            yield $line => array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
        }
        fclose($fh);
    }

    /** Deterministic "x9"-style price inside the subcategory's range. */
    private function generatePrice(string $id, string $subcategory): float
    {
        [$min, $max] = config("catalog.price_ranges.{$subcategory}") ?? config('catalog.default_price_range');
        // Skew toward the low end of the range (most items are mid/low priced).
        $t = (crc32($id.'|'.$subcategory) % 10000) / 10000;
        $raw = $min + ($max - $min) * $t * $t;
        $step = $raw >= 1000 ? 100 : 10;

        return max($min, (float) (ceil($raw / $step) * $step - ($step === 100 ? 10 : 1)));
    }
}
