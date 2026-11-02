<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Search\SearchService;
use Illuminate\Console\Command;

/**
 * Prints the top results for a fixed set of queries (the assignment's
 * examples + extras) so relevance can be eyeballed after every change.
 */
class EvalSearch extends Command
{
    protected $signature = 'search:eval {query?* : Custom queries (default: built-in set)} {--top=5}';

    protected $description = 'Show top search results for a set of evaluation queries';

    private const QUERIES = [
        'שואב אבק',
        'iPhone 16',
        'אייפון',
        'מתנה למישהו שאוהב לבשל',
        'משהו שיעזור לנקות שערות של כלב מהספה',
        'air fryer',
        'מכונת קפה',
        'gift for a coffee lover',
        'אוזניות עם ביטול רעשים',
        'משהו לילד בן 8',
        'Dyson',
        'qwertyzxcv',
    ];

    public function handle(SearchService $search): int
    {
        $queries = $this->argument('query') ?: self::QUERIES;
        foreach ($queries as $q) {
            $r = $search->search($q, (int) $this->option('top'));
            $names = Product::whereIn('id', array_column($r->hits, 'id'))->get()->keyBy('id');
            $this->line('');
            $this->info("» {$q}".($r->degraded ? '  [degraded: keyword only]' : ''));
            if ($r->hits === []) {
                $this->line('   (no results)');
            }
            foreach ($r->hits as $i => $hit) {
                $p = $names[$hit['id']];
                $this->line(sprintf('  %d. %s | %s | ₪%s  [%s %.4f]', $i + 1, $p->name, $p->subcategory, $p->price, implode('+', $hit['sources']), $hit['score']));
            }
        }

        return self::SUCCESS;
    }
}
