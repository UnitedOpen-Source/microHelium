<?php

namespace Helium\Providers;

use App\Http\Middleware\SecurityHeaders;
use App\Services\Similarity\JplagSimilarityEngine;
use App\Services\Similarity\SimilarityEngineInterface;
use Illuminate\Support\Facades\Blade;
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
        // Issue #90 -- stamps an inline <script> with the per-request CSP
        // nonce SecurityHeaders generated, so the policy can refuse inline
        // script in general without refusing ours:  <script @cspNonce>
        Blade::directive('cspNonce', fn () => "<?php echo 'nonce=\"'.e(request()->attributes->get(\\".SecurityHeaders::class."::NONCE_ATTRIBUTE)).'\"'; ?>");
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
