<?php

namespace Helium\Providers;

use App\Services\Similarity\JplagSimilarityEngine;
use App\Services\Similarity\SimilarityEngineInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Real engine by default; tests override this binding with
        // Tests\Support\Similarity\FakeSimilarityEngine so the suite never
        // shells out to a real JPlag jar (see docs/specs/42-similarity.md).
        $this->app->bind(SimilarityEngineInterface::class, JplagSimilarityEngine::class);
    }
}
