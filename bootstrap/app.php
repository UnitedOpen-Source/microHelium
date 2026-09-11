<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
            \Illuminate\Support\Facades\Route::middleware(['throttle:20,1', 'webcast.auth'])
                ->group(__DIR__.'/../routes/webcast_consumer.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin' => \Helium\Http\Middleware\IsAdminMiddleware::class,
            'role' => \App\Http\Middleware\CheckRole::class,
            'webcast.auth' => \App\Http\Middleware\AuthenticateWebcastCredential::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
