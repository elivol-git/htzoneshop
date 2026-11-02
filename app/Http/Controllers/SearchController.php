<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Search\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request, SearchService $search): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:200'],
        ]);
        $started = hrtime(true);

        $result = $search->search($validated['q']);

        $ids = array_column($result->hits, 'id');
        $products = Product::query()->whereIn('id', $ids)->get()->keyBy('id');

        $items = [];
        foreach ($result->hits as $hit) {
            if (! $p = $products->get($hit['id'])) {
                continue;
            }
            $items[] = [
                'id' => $p->external_id,
                'name' => $p->name,
                'brand' => $p->brand,
                'category' => $p->subcategory ?: $p->category,
                'price' => (float) $p->price,
                'image_url' => $p->image_url,
            ] + ($request->boolean('debug') && config('app.debug') ? ['_score' => round($hit['score'], 5), '_sources' => $hit['sources']] : []);
        }

        return response()->json([
            'query' => $validated['q'],
            'count' => count($items),
            'degraded' => $result->degraded,
            'took_ms' => (int) ((hrtime(true) - $started) / 1e6),
            'results' => $items,
        ] + ($request->boolean('debug') && config('app.debug') ? ['debug' => $result->debug] : []));
    }
}
