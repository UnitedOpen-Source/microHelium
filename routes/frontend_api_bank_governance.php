<?php

use App\Http\Controllers\FrontendApi\BankGovernanceController;
use Illuminate\Support\Facades\Route;

// Issue #46 -- docs/specs/46-bank-ownership.md. Real data/mutation
// endpoints for resources/js/features/BankGovernance.vue.
//
// Deliberately `auth` only (not `admin`): the /backend/bank-governance
// *page* stays admin-gated in routes/frontend.php for now ("UI inicial é
// administrativa"), but the API itself must authorize by policy
// (admin bypass, or org-editor membership) rather than by role alone --
// "não apenas retirar admin da rota" -- so that a future non-admin
// collaboration screen can reuse this same contract unchanged. Every
// mutating check is still re-verified server-side per request; capabilities
// in the GET response are presentation hints only.
Route::prefix('api/frontend')->middleware(['auth'])->group(function () {
    Route::get('/bank-governance', [BankGovernanceController::class, 'index'])
        ->name('api.frontend.bank-governance.index');
    Route::patch('/bank-governance/{bank}', [BankGovernanceController::class, 'update'])
        ->name('api.frontend.bank-governance.update');
});
