<?php

use App\Http\Controllers\FrontendApi\OrganizationController;
use Illuminate\Support\Facades\Route;

// Issue #188 -- a gestao de organizacoes que o #46 deixou para depois.
//
// `admin` e nao so `auth`, ao contrario do vizinho
// routes/frontend_api_bank_governance.php, e a diferenca e deliberada:
// aquele autoriza POR POLITICA porque um editor de organizacao pode mexer
// nos problemas dela. Aqui o assunto e QUEM E EDITOR -- deixar um editor
// gerenciar a propria membership seria deixar que ele se promova, e
// promover a si mesmo e a unica coisa que uma permissao nunca pode
// permitir.
Route::prefix('api/frontend')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/organizations', [OrganizationController::class, 'index'])
        ->name('api.frontend.organizations.index');
    Route::post('/organizations', [OrganizationController::class, 'store'])
        ->name('api.frontend.organizations.store');
    Route::patch('/organizations/{organization}', [OrganizationController::class, 'update'])
        ->name('api.frontend.organizations.update');
    Route::post('/organizations/{organization}/members', [OrganizationController::class, 'addMember'])
        ->name('api.frontend.organizations.members.add');
    Route::delete('/organizations/{organization}/members/{userId}', [OrganizationController::class, 'removeMember'])
        ->name('api.frontend.organizations.members.remove');
});
