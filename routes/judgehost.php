<?php

use App\Http\Controllers\Judgehost\WorkController;
use Illuminate\Support\Facades\Route;

// Issue #53 -- everything a judge machine can reach.
//
// Registered from bootstrap/app.php's withRouting(then: ...) with its own
// middleware stack, outside both the `web` group (no session, no CSRF --
// this client has no browser) and the `api` group (no Sanctum), exactly
// like the webcast consumer routes from #44.
//
// Pull only: the machine polls these, and nothing in the system ever
// connects to it. That is the premise -- a partner institution's judge
// needs no inbound port.
Route::post('/api/judgehost/register', [WorkController::class, 'register']);
Route::post('/api/judgehost/fetch-work', [WorkController::class, 'fetchWork']);
Route::post('/api/judgehost/runs/{run}/give-back', [WorkController::class, 'giveBackRun'])->whereNumber('run');
