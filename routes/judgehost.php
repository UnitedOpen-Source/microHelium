<?php

use App\Http\Controllers\Judgehost\PayloadController;
use App\Http\Controllers\Judgehost\ResultController;
use App\Http\Controllers\Judgehost\WorkController;
use Illuminate\Support\Facades\Route;

// Issue #53 -- everything a judge machine can reach.
//
// The path is the one docs/specs/53-distributed-judging.md named, `v1`
// included. The version matters more here than on an internal route: the
// client runs in someone else's rack, on their upgrade schedule, so the day
// this contract changes there has to be somewhere for the old one to keep
// living. Renamed while nothing consumes it -- the cost only goes up.
//
// Registered from bootstrap/app.php's withRouting(then: ...) with its own
// middleware stack, outside both the `web` group (no session, no CSRF --
// this client has no browser) and the `api` group (no Sanctum), exactly
// like the webcast consumer routes from #44.
//
// Pull only: the machine polls these, and nothing in the system ever
// connects to it. That is the premise -- a partner institution's judge
// needs no inbound port.
Route::post('/api/remote-judges/v1/register', [WorkController::class, 'register']);
Route::post('/api/remote-judges/v1/fetch-work', [WorkController::class, 'fetchWork']);
Route::post('/api/remote-judges/v1/runs/{run}/give-back', [WorkController::class, 'giveBackRun'])->whereNumber('run');

// The bytes and the verdict. Every one of these names a run in its URL and
// is scoped to the machine currently holding it (App\Http\Controllers\
// Judgehost\HoldsRun) -- a judgehost reads a contestant's source and a
// problem's hidden test data only while it is actually judging that run,
// and never afterwards.
Route::get('/api/remote-judges/v1/runs/{run}/source', [PayloadController::class, 'source'])->whereNumber('run');
Route::get('/api/remote-judges/v1/runs/{run}/testcases', [PayloadController::class, 'testCases'])->whereNumber('run');
Route::get('/api/remote-judges/v1/runs/{run}/testcases/{testCase}/{kind}', [PayloadController::class, 'testCaseFile'])
    ->whereNumber('run')->whereNumber('testCase')->whereIn('kind', ['input', 'output']);
Route::get('/api/remote-judges/v1/runs/{run}/package/{kind}', [PayloadController::class, 'packageScript'])
    ->whereNumber('run')->whereIn('kind', ['compile', 'run', 'compare']);
Route::post('/api/remote-judges/v1/runs/{run}/result', [ResultController::class, 'store'])->whereNumber('run');

// The verdict vocabulary for a contest -- answers are per contest and
// installations rename them, so an agent must not hardcode BOCA's.
Route::get('/api/remote-judges/v1/contests/{contest}/answers', [ResultController::class, 'vocabulary'])->whereNumber('contest');
