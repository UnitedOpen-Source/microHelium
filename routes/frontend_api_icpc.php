<?php

use App\Http\Controllers\FrontendApi\IcpcReportController;
use App\Http\Controllers\FrontendApi\ResultsBundleController;
use Illuminate\Support\Facades\Route;

// Issue #89 -- the ICPC standings file (BOCA's src/admin/report/icpc.php).
//
// Required from the bottom of routes/web.php, so it inherits the `web`
// group (session + CSRF) the rest of /api/frontend/* runs under. Admin
// only: this is the whole field of play in one file, including teams and
// their placements, and it is produced once, after the contest, by whoever
// files the results.
Route::prefix('api/frontend')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/contests/{contest}/icpc-report', [IcpcReportController::class, 'download'])
        ->whereNumber('contest')
        ->name('api.frontend.contests.icpc-report');

    // Issue #271 -- o pacote de resultados, para envio a um agregador
    // nacional. Mesmo guard e mesma razao do relatorio acima, e a mesma
    // audiencia: quem envia os resultados depois da prova.
    Route::get('/contests/{contest}/results-bundle', [ResultsBundleController::class, 'download'])
        ->whereNumber('contest')
        ->name('api.frontend.contests.results-bundle');
});
