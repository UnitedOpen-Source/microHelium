<?php

use App\Http\Controllers\FrontendApi\JudgingHealthController;
use Illuminate\Support\Facades\Route;

// Issue #191 -- /api/frontend/judging-health. Required do fim do
// routes/web.php, entao herda o grupo `web` (sessao + CSRF) sob o qual o
// proprio web.php e registrado, como o resto da superficie /api/frontend/*
// (docs/specs/README.md).
//
// Mesma audiencia da rota de apresentacao em routes/frontend.php
// (`/judge/health`): admin, judge e site. Uma tela que o operador abre e
// cujas requisicoes todas respondem 403 e pior do que tela nenhuma.
Route::prefix('api/frontend')->middleware(['auth', 'role:admin,judge,site'])->group(function () {
    Route::get('/judging-health', [JudgingHealthController::class, 'index']);
});
