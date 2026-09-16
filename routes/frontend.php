<?php

use App\Http\Controllers\Backend\ContestOperationsController;
use Illuminate\Support\Facades\Route;

// Presentation only. Data/mutation contracts: docs/specs/README.md.
// These routes neither bypass authorization nor implement domain operations.
Route::view('/practice', 'features.practice')->name('practice');
Route::view('/practice/history', 'features.practice-history')->middleware('auth')->name('practice.history');
Route::view('/practice/problems/{problemId}', 'features.practice-problem')->whereNumber('problemId')->name('practice.problem');
Route::view('/judge/health', 'features.judging-health')->middleware(['auth', 'role:admin,judge,site'])->name('judge.health');
// Issue #144 -- BOCA's src/judge/history.php and src/staff/report/. Shells
// only; the gate that matters is repeated on the data routes in
// routes/frontend_api_reports.php, and the scope inside
// ContestReportController. Kept to the same audience as those routes so a
// coordinator is not shown a page whose every request will 403.
Route::view('/judge/history', 'features.judge-history')->middleware(['auth', 'role:admin,judge'])->name('judge.history');
Route::view('/staff/report', 'features.site-report')->middleware(['auth', 'role:admin,staff,site'])->name('staff.report');
Route::prefix('backend')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/contest/{contest}/operations', [ContestOperationsController::class, 'show'])->name('backend.contest.operations');
    Route::post('/contest/{contest}/finalize', [ContestOperationsController::class, 'finalize'])->name('backend.contest.finalize');
    Route::post('/contest/{contest}/unfreeze', [ContestOperationsController::class, 'unfreeze'])->name('backend.contest.unfreeze');
    // Issue #198/#233 -- intervalos removidos, na operacao da competicao.
    Route::post('/contest/{contest}/time-adjustments', [ContestOperationsController::class, 'storeTimeAdjustment'])
        ->name('backend.contest.time-adjustments.store');
    Route::delete('/contest/{contest}/time-adjustments/{adjustment}', [ContestOperationsController::class, 'destroyTimeAdjustment'])
        ->name('backend.contest.time-adjustments.destroy');
    Route::view('/tools', 'features.tools')->name('backend.tools');
    Route::view('/similarity', 'features.similarity')->name('backend.similarity');
    Route::view('/webcast', 'features.webcast')->name('backend.webcast');
    Route::view('/bank-governance', 'features.bank-governance')->name('backend.bank-governance');
    Route::view('/managed-accounts', 'features.managed-accounts')->name('backend.managed-accounts');
    // Issue #53, fase 4. `admin` e nao `judge`, igual as rotas de dados em
    // routes/frontend_api_judgehosts.php: acrescentar ou desligar maquina
    // muda a capacidade do evento, e isso e decisao de quem dirige a prova.
    Route::view('/judge-machines', 'features.judge-machines')->name('backend.judge-machines');
    // Issue #188/#233. `admin`, igual as rotas de dados em
    // routes/frontend_api_organizations.php: aqui o assunto e QUEM E
    // EDITOR, e deixar um editor gerenciar a propria membership seria
    // deixar que ele se promova.
    Route::view('/organizations', 'features.organizations')->name('backend.organizations');
});
