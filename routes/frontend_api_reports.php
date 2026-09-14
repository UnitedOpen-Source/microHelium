<?php

use App\Http\Controllers\FrontendApi\ContestReportController;
use Illuminate\Support\Facades\Route;

// Issue #144 -- the data behind BOCA's src/staff/report/ and
// src/judge/history.php.
//
// Required from the bottom of routes/web.php, so these inherit the `web`
// group (session + CSRF) the rest of /api/frontend/* runs under; see
// docs/specs/README.md's "Contrato comum proposto v1".
//
// Two groups, not one, because the two reports do not have the same
// audience. Both answer with unfrozen data, which is what issue #134 found
// leaking through scoreboard/export -- the difference between that bug and
// this is that neither of these is reachable by a team at all.
Route::prefix('api/frontend/reports')->group(function () {

    // The site coordinator's cut, and the whole-contest cut for an admin.
    // Staff are included because at a real site they are the local
    // organisation -- the same reasoning that put them on the S.O.S. queue
    // (#139). Judges are not: a judge does not run a room.
    Route::get('/site', [ContestReportController::class, 'site'])
        ->middleware(['auth', 'role:admin,staff,site'])
        ->name('api.frontend.reports.site');

    // The jury's cut. Team names next to unfrozen verdicts and the name of
    // the person who gave each one; BOCA files it under judge/ and so do we.
    Route::get('/judge-history', [ContestReportController::class, 'judgeHistory'])
        ->middleware(['auth', 'role:admin,judge'])
        ->name('api.frontend.reports.judge-history');
});
