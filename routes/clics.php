<?php

use App\Http\Controllers\Clics\ContestApiController;
use Illuminate\Support\Facades\Route;

/**
 * Issue #195 -- a Contest API da ICPC, fase 1.
 *
 * Fora de routes/api.php de proposito: aquele arquivo documenta um contrato
 * PROPRIO sob auth:sanctum, e isto implementa uma especificacao EXTERNA e
 * versionada cujo contrato normativo mora em ccs-specs.icpc.io. O mesmo
 * raciocinio ja escrito para o grupo /api/frontend/*.
 *
 * Leitura anonima, e e assim que a spec funciona: um placar publico e um
 * shadow que acompanha a prova nao tem conta aqui. O que protege nao e
 * autenticacao, e o corte do congelamento -- /judgements e /scoreboard
 * respondem a versao congelada para quem nao e da organizacao, e /awards so
 * existe depois de finalizar (#202).
 *
 * Quem se autentica com Sanctum e e da banca recebe a versao descongelada
 * pelo mesmo URL, que e o que um shadow da propria organizacao precisa.
 */
Route::prefix('api/clics')->group(function () {
    Route::get('/', [ContestApiController::class, 'index']);
    Route::get('/access', [ContestApiController::class, 'access']);
    Route::get('/contests', [ContestApiController::class, 'contests']);
    Route::get('/contests/{contest}', [ContestApiController::class, 'contest']);
    Route::get('/contests/{contest}/state', [ContestApiController::class, 'state']);
    Route::get('/contests/{contest}/problems', [ContestApiController::class, 'problems']);
    Route::get('/contests/{contest}/teams', [ContestApiController::class, 'teams']);
    Route::get('/contests/{contest}/organizations', [ContestApiController::class, 'organizations']);
    Route::get('/contests/{contest}/groups', [ContestApiController::class, 'groups']);
    Route::get('/contests/{contest}/languages', [ContestApiController::class, 'languages']);
    Route::get('/contests/{contest}/judgement-types', [ContestApiController::class, 'judgementTypes']);
    Route::get('/contests/{contest}/submissions', [ContestApiController::class, 'submissions']);
    Route::get('/contests/{contest}/judgements', [ContestApiController::class, 'judgements']);
    Route::get('/contests/{contest}/scoreboard', [ContestApiController::class, 'scoreboard']);
    Route::get('/contests/{contest}/awards', [ContestApiController::class, 'awards']);
});
