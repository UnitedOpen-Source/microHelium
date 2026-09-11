<?php

use App\Http\Controllers\AccountActivationController;
use App\Http\Controllers\Api\Frontend\ManagedAccountsController;
use Illuminate\Support\Facades\Route;

// Issue #47 -- contas gerenciadas e privacidade. Session + CSRF (web
// guard, since this file is required from routes/web.php), NOT the
// auth:sanctum bearer-token group in routes/api.php -- see the shared
// contract in docs/specs/README.md. Admin-only for this first delivery
// ("Acesso inicial admin apenas").
Route::prefix('api/frontend')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/managed-accounts', [ManagedAccountsController::class, 'index']);
    Route::post('/managed-accounts', [ManagedAccountsController::class, 'store']);
});

// Public, single-use activation flow for accounts created above.
// Deliberately NOT behind 'auth' -- the whole point is the user has no
// usable password yet. Throttled per the spec's "rate limit" requirement.
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/activate/{token}', [AccountActivationController::class, 'show'])->name('activation.show');
    Route::post('/activate/{token}', [AccountActivationController::class, 'store'])->name('activation.store');
});
