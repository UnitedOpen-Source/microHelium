<?php

use App\Http\Controllers\Judgehost\PayloadController;
use App\Http\Controllers\Judgehost\ResultController;
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

// The bytes and the verdict. Every one of these names a run in its URL and
// is scoped to the machine currently holding it (App\Http\Controllers\
// Judgehost\HoldsRun) -- a judgehost reads a contestant's source and a
// problem's hidden test data only while it is actually judging that run,
// and never afterwards.
Route::get('/api/judgehost/runs/{run}/source', [PayloadController::class, 'source'])->whereNumber('run');
Route::get('/api/judgehost/runs/{run}/testcases', [PayloadController::class, 'testCases'])->whereNumber('run');
Route::get('/api/judgehost/runs/{run}/testcases/{testCase}/{kind}', [PayloadController::class, 'testCaseFile'])
    ->whereNumber('run')->whereNumber('testCase')->whereIn('kind', ['input', 'output']);
Route::get('/api/judgehost/runs/{run}/package/{kind}', [PayloadController::class, 'packageScript'])
    ->whereNumber('run')->whereIn('kind', ['compile', 'run', 'compare']);
Route::post('/api/judgehost/runs/{run}/result', [ResultController::class, 'store'])->whereNumber('run');

// The verdict vocabulary for a contest -- answers are per contest and
// installations rename them, so an agent must not hardcode BOCA's.
Route::get('/api/judgehost/contests/{contest}/answers', [ResultController::class, 'vocabulary'])->whereNumber('contest');
