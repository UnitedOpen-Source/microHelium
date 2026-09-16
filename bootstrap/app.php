<?php

use App\Http\Middleware\AuthenticateJudgehost;
use App\Http\Middleware\AuthenticateWebcastCredential;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\ClicsHeaders;
use App\Http\Middleware\SecurityHeaders;
use Helium\Http\Middleware\IsAdminMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Issue #44 -- the webcast-credential-authenticated scoreboard
            // route lives entirely outside the `web` (session/CSRF) and
            // `api` (Sanctum) groups: its own middleware stack, nothing
            // inherited. See routes/webcast_consumer.php.
            // throttle runs BEFORE webcast.auth so that wrong-token
            // guesses -- not just successful reads -- count against the
            // limit; putting auth first would let a brute-force attempt
            // guess unlimited tokens as long as each guess fails.
            Route::middleware(['throttle:20,1', 'webcast.auth'])
                ->group(__DIR__.'/../routes/webcast_consumer.php');

            // Issue #53 -- the judge-machine surface, same reasoning: its
            // own guard, no session, no Sanctum. The throttle is looser
            // than the webcast one because polling for work is the normal
            // mode of operation here, not an anomaly.
            // SubstituteBindings is explicit here because this group is
            // registered outside the `api` group, which is where Laravel
            // normally supplies it. Without it a `Run $run` argument is
            // resolved by the container into an empty model instead of the
            // row named in the URL, and every ownership check on it compares
            // null against a real id -- passing the 403 tests vacuously
            // while denying the host that legitimately holds the run.
            Route::middleware([
                'throttle:120,1',
                SubstituteBindings::class,
                'judgehost.auth',
            ])
                ->group(__DIR__.'/../routes/judgehost.php');

            // Issue #195 -- a Contest API da ICPC, fase 1.
            //
            // Grupo proprio pela mesma razao dos dois acima: nao herda
            // sessao, CSRF nem o contrato de auth:sanctum de
            // routes/api.php. `auth:sanctum` entra como middleware OPCIONAL
            // (`sanctum` sem `auth:`) porque a leitura e anonima por
            // desenho -- o que muda com a autenticacao nao e o acesso, e se
            // a resposta vem congelada ou nao.
            //
            // CORS liberado porque a spec pede
            // `Access-Control-Allow-Origin: *`: as ferramentas que
            // consomem isto sao paginas servidas de outro lugar.
            Route::middleware([
                'throttle:300,1',
                SubstituteBindings::class,
                ClicsHeaders::class,
            ])
                ->group(__DIR__.'/../routes/clics.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Issue #90 -- security headers belong to the response, not to one
        // deployment's nginx config. Appended so it wraps every route,
        // including the webcast consumer group registered above.
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'admin' => IsAdminMiddleware::class,
            'role' => CheckRole::class,
            'webcast.auth' => AuthenticateWebcastCredential::class,
            'judgehost.auth' => AuthenticateJudgehost::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
