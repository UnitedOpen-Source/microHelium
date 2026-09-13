<?php

use App\Http\Controllers\FrontendApi\PracticeController;
use Illuminate\Support\Facades\Route;

// Issue #43 -- docs/specs/43-practice.md. Real data/mutation endpoints for
// resources/js/features/Practice.vue (/practice, /practice/problems/{id},
// /practice/history).
//
// Required from the bottom of routes/web.php, so these inherit the `web`
// group (session + CSRF) that web.php is registered under -- per
// docs/specs/README.md's shared contract this is a session-authenticated
// JSON API, not a bearer-token one.
Route::prefix('api/frontend')->group(function () {
    // Public on purpose: the library is readable without an account, and an
    // anonymous visitor is invited to sign in rather than turned away --
    // "usuário sem login pode ler e recebe convite para entrar". The
    // per-viewer fields in these responses are why the controller sends
    // Cache-Control: private, no-store on every one of them.
    Route::get('/practice/problems', [PracticeController::class, 'index'])
        ->name('api.frontend.practice.problems.index');
    Route::get('/practice/problems/{problem}', [PracticeController::class, 'show'])
        ->whereNumber('problem')
        ->name('api.frontend.practice.problems.show');

    Route::middleware(['auth'])->group(function () {
        // "Envio exige conta habilitada e política de rate limit." The
        // account check is in the controller (it needs to answer 403 with a
        // reason); the frequency policy is here. Judging is expensive and
        // practice has no contest window to bound it, so this is the only
        // thing standing between one account and an unbounded judge queue.
        Route::post('/practice/problems/{problem}/runs', [PracticeController::class, 'storeRun'])
            ->whereNumber('problem')
            ->middleware('throttle:20,1')
            ->name('api.frontend.practice.runs.store');

        Route::get('/practice/history', [PracticeController::class, 'history'])
            ->name('api.frontend.practice.history');
    });
});
