<?php

namespace Helium\Providers;

use App\Http\Middleware\SecurityHeaders;
use App\Services\Similarity\JplagSimilarityEngine;
use App\Services\Similarity\SimilarityEngineInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
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
        // Issue #268 -- o auto-cadastro deixa de comer o orcamento do login.
        //
        // `ThrottleRequests::resolveRequestSignature()` devolve
        // `sha1(dominio|ip)` -- SEM a rota. Duas rotas com `throttle:5,1` no
        // mesmo dominio dividem UM balde por IP, entao o limite que a #275
        // pos no `/register` estava descontando das tentativas de `/login`.
        //
        // Em laboratorio de prova, onde a sede inteira sai por um NAT, cinco
        // cadastros recusados trancavam o login de todo mundo por um minuto.
        // Foi a suite que pegou: o teste de throttle do login passou a falhar
        // quando a ordem dos arquivos de teste mudou.
        //
        // Limitador NOMEADO resolve porque a chave passa a levar o nome:
        // `register|<ip>` e `<ip>` sao baldes diferentes.
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));

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
