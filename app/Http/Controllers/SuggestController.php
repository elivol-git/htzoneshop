<?php

namespace App\Http\Controllers;

use App\Services\Search\Suggester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuggestController extends Controller
{
    public function __invoke(Request $request, Suggester $suggester): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'max:100']]);

        return response()->json([
            'query' => $validated['q'],
            'suggestions' => $suggester->suggest($validated['q']),
        ]);
    }
}
