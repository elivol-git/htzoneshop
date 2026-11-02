<?php

namespace App\Providers;

use App\Services\Search\Embedder;
use App\Services\Search\HuggingFaceEmbedder;
use App\Services\Search\QueryExpander;
use App\Services\Search\SearchService;
use App\Services\Search\TextNormalizer;
use App\Services\Search\Vocabulary;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TextNormalizer::class, fn () => new TextNormalizer(config('search.stopwords', [])));
        $this->app->singleton(Embedder::class, fn () => HuggingFaceEmbedder::fromConfig());
        $this->app->bind(QueryExpander::class, fn ($app) => new QueryExpander(
            $app->make(TextNormalizer::class),
            config('search.synonyms', []),
            Vocabulary::load(),
        ));
        $this->app->bind(SearchService::class, fn ($app) => new SearchService(
            $app->make(TextNormalizer::class),
            $app->make(QueryExpander::class),
            $app->make(\App\Services\Search\KeywordSearch::class),
            $app->make(\App\Services\Search\VectorIndex::class),
            $app->make(\App\Services\Search\QueryEmbeddingCache::class),
            config('search'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
