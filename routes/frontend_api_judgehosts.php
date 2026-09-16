<?php

use App\Http\Controllers\FrontendApi\JudgehostController;
use Illuminate\Support\Facades\Route;

// Issue #53, phase 4 -- /api/frontend/judgehosts. Required from the bottom
// of routes/web.php, so these inherit the `web` group (session + CSRF) that
// web.php is registered under: per docs/specs/README.md this is a
// session-authenticated JSON API for the application's own interface, not
// routes/api.php's bearer-token contract.
//
// Not to be confused with routes/judgehost.php, which is the protocol the
// judge MACHINES speak -- its own guard, no session, no Sanctum. These
// routes are about those machines; they are not spoken by them.
//
// admin, not judge: adding or disabling a judge machine changes the
// capacity of the event, which is the contest director's call. A judge
// judges the contest they were given.
Route::prefix('api/frontend')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/judgehosts', [JudgehostController::class, 'index']);
    Route::post('/judgehosts', [JudgehostController::class, 'store']);
    Route::patch('/judgehosts/{id}', [JudgehostController::class, 'update'])->whereNumber('id');

    // Issue #196 -- a divergencia medida entre as maquinas.
    //
    // Na mesma tela porque e a mesma pergunta: o #53 fase 4 responde "quais
    // maquinas existem e estao vivas", e isto responde "elas sao
    // comparaveis?". Nenhum veredito muda -- o #130 decidiu que divergencia
    // e avisada e nao compensada, e ate aqui nao havia nem o aviso.
    Route::get('/judgehosts/calibration', [JudgehostController::class, 'calibration']);
});
