<?php

use App\Http\Controllers\Api\ClarificationController;
use App\Http\Controllers\Api\ContestController;
use App\Http\Controllers\Api\ContestFinalizationController;
use App\Http\Controllers\Api\ContestTimeAdjustmentController;
use App\Http\Controllers\Api\ProblemController;
use App\Http\Controllers\Api\RejudgingController;
use App\Http\Controllers\Api\RunController;
use App\Http\Controllers\Api\ScoreboardController;
use App\Http\Controllers\Api\TokenController;
use App\Models\Contest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Issue #134 -- this file used to be one flat `auth:sanctum` group with
| three `role:` exceptions bolted on. `auth:sanctum` answers "who are you",
| never "may you", so every route that did not carry its own `role:` was
| reachable by any account holding a token -- including the `team` token
| every competitor is given. Reproduced, not inferred: a team could
| DELETE /api/problems/{id}, POST /api/contests/{id}/deactivate and
| DELETE /api/contests/{id} mid-contest, and pull the full problem package
| (which carries the hidden input/ and output/ test data) from
| GET /api/problems/{id}/export.
|
| The group is therefore split in two, and the split is the contract:
|
|   1. the competitor surface -- reads a team needs to play, plus the two
|      writes that ARE a team's job (submit a run, ask a clarification);
|   2. the staff surface -- everything that changes the shape of the event
|      or discloses material a competitor must not see.
|
| Api\ProblemController and Api\ContestController still have no internal
| authorization of their own, so the route is the whole of the check for
| them: adding an action to one of those controllers without deciding which
| of the two groups it belongs in is what created this bug. That decision is
| now forced -- tests/Feature/Api/ApiRouteAuthorizationTest.php walks the
| live route list and fails on any auth:sanctum route it has not been told
| about, so a new route cannot quietly inherit the open half again.
*/

/*
|--------------------------------------------------------------------------
| Getting in (issue #159)
|--------------------------------------------------------------------------
|
| Deliberately outside the group below: this is the route you call when you
| do not have a token yet, and before it existed there was no such route --
| `personal_access_tokens` was never migrated and nothing anywhere called
| createToken(), so the entire authenticated surface was unreachable by any
| client that is not a browser.
|
| Throttled at the same 5/minute as the web login in routes/web.php. The
| limit is per IP and counts failures and successes alike, which is the
| point: an unthrottled credentials endpoint is a password-guessing
| appliance for a participant list that is public by nature.
*/
Route::post('/tokens', [TokenController::class, 'store'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    /*
    |----------------------------------------------------------------------
    | Competitor surface -- any authenticated account, team included
    |----------------------------------------------------------------------
    |
    | Reads only, plus submitting a run and asking a clarification. Each
    | controller here already narrows the rows it returns to the caller
    | (Api\RunController::index()/show() and Api\ClarificationController::
    | index()/show() fall back to "own rows, plus broadcasts" for anyone who
    | is not admin/judge), so the role gate below would be the wrong tool:
    | teams are supposed to reach these, just not to see each other.
    */
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // The other half of #159: a credential you cannot see or withdraw is
    // one you have to assume is still out there. These are scoped to the
    // caller's own tokens inside the controller -- not by a `role:` gate --
    // because every account, a team's included, needs to be able to revoke
    // a token it typed into a machine in a shared lab.
    Route::get('/tokens', [TokenController::class, 'index']);
    Route::delete('/tokens/current', [TokenController::class, 'destroyCurrent']);
    Route::delete('/tokens/{token}', [TokenController::class, 'destroy'])->whereNumber('token');

    Route::apiResource('contests', ContestController::class)->only(['index', 'show']);
    Route::get('/contests/{contest}/status', [ContestController::class, 'status']);

    // whereNumber, because /clarifications/pending (the judge queue, in the
    // staff group below) would otherwise be swallowed by show()'s
    // {clarification} placeholder and answered with a 404 -- the two used to
    // be declared in one block where source order alone kept them apart, and
    // splitting the group by audience took that guarantee away.
    Route::apiResource('clarifications', ClarificationController::class)
        ->only(['index', 'store', 'show'])
        ->whereNumber('clarification');

    Route::apiResource('problems', ProblemController::class)->only(['index', 'show']);
    // The rendered statement, not the package: description/ only, never
    // input/ or output/. This is the file the team is meant to read.
    Route::get('/problems/{problem}/download', [ProblemController::class, 'download']);

    // `only` here is not a narrowing of what was reachable before: the
    // controller never defined update()/destroy(), so apiResource was
    // publishing two routes whose only possible outcome was a 500. Runs are
    // an append-only record of the contest anyway -- a wrong verdict is
    // fixed by rejudging, not by editing or deleting the submission.
    Route::apiResource('runs', RunController::class)->only(['index', 'store', 'show']);
    Route::get('/runs/{run}/source', [RunController::class, 'downloadSource']);

    Route::get('/contests/{contest}/scoreboard', [ScoreboardController::class, 'index']);
    Route::get('/contests/{contest}/my-score', [ScoreboardController::class, 'userScore']);

    /*
    |----------------------------------------------------------------------
    | Staff surface
    |----------------------------------------------------------------------
    |
    | `role:` (App\Http\Middleware\CheckRole) is the mechanism already in
    | use for judge()/rejudge()/answer(); the two gates below only differ in
    | who they let through, following what routes/web.php already decided
    | for the same operations on the web side.
    */

    // Judge or admin: the day-to-day work of running the event. Judges own
    // the clarification queue and the problem set -- exporting a package
    // (hidden test data included) is part of preparing and checking a
    // problem, which is exactly why it cannot stay on the competitor side.
    Route::middleware('role:judge,admin')->group(function () {
        Route::get('/clarifications/pending', [ClarificationController::class, 'pending']);
        Route::apiResource('clarifications', ClarificationController::class)->only(['destroy']);
        Route::put('/clarifications/{clarification}/answer', [ClarificationController::class, 'answer']);

        Route::apiResource('problems', ProblemController::class)->only(['store', 'update', 'destroy']);
        Route::get('/problems/{problem}/export', [ProblemController::class, 'exportPackage']);

        // Issue #153. The hidden test cases in full -- disk paths,
        // input_hash, output_hash. GET /problems/{problem} used to load
        // this relation into the competitor-facing body: a sha256 of the
        // hidden input turns guessing into verifying, which is the same
        // disclosure /export was moved here for, only in digest form. The
        // competitor response keeps `test_cases_count` and nothing else.
        Route::get('/problems/{problem}/test-cases', [ProblemController::class, 'testCases']);

        // Issue #202 -- finalizar a prova e derivar a premiacao.
        //
        // Junto do resto do que a banca opera. A premiacao e legivel antes
        // de finalizar de proposito: a organizacao precisa conferir quem
        // receberia o que ANTES de assinar embaixo, e como a rota e de staff
        // conferir nao vaza nada.
        Route::get('/contests/{contest}/finalize/preflight', [ContestFinalizationController::class, 'preflight']);
        Route::post('/contests/{contest}/finalize', [ContestFinalizationController::class, 'finalize']);
        Route::get('/contests/{contest}/awards', [ContestFinalizationController::class, 'awards']);

        // Issue #198 -- intervalos removidos da prova (queda de energia numa
        // sede, por exemplo). Junto do resto do que a banca opera, e nao no
        // grupo de admin: quem decide que a sede perdeu quarenta minutos e
        // quem esta conduzindo a prova.
        Route::get('/contests/{contest}/time-adjustments', [ContestTimeAdjustmentController::class, 'index']);
        Route::post('/contests/{contest}/time-adjustments', [ContestTimeAdjustmentController::class, 'store']);
        Route::delete('/contests/{contest}/time-adjustments/{adjustment}', [ContestTimeAdjustmentController::class, 'destroy']);

        Route::post('/runs/{run}/rejudge', [RunController::class, 'rejudge']);

        // Issue #192 -- rejulgamento EM LOTE, com previa.
        //
        // Aqui e nao no grupo de admin, junto do rejulgamento de um run so:
        // quem pode rejulgar um envio pode rejulgar o problema inteiro, e a
        // diferenca entre as duas coisas e de escala e nao de autoridade. O
        // que protege contra o acidente nao e o perfil -- e a previa, o
        // motivo obrigatorio e a exclusao dos aceitos por padrao.
        Route::get('/contests/{contest}/rejudgings', [RejudgingController::class, 'index']);
        Route::post('/contests/{contest}/rejudgings/dry-run', [RejudgingController::class, 'dryRun']);
        Route::post('/contests/{contest}/rejudgings', [RejudgingController::class, 'store']);
        Route::get('/rejudgings/{rejudging}', [RejudgingController::class, 'show']);
        Route::post('/rejudgings/{rejudging}/apply', [RejudgingController::class, 'apply']);
        Route::post('/rejudgings/{rejudging}/cancel', [RejudgingController::class, 'cancel']);
        Route::put('/runs/{run}/judge', [RunController::class, 'judge']);

        // Issue #138 -- the verification gate. Staff-only for the obvious
        // reason and for a second one: verifying is what publishes a
        // verdict, so a team able to call it could release its own.
        Route::put('/runs/{run}/verify', [RunController::class, 'verify']);
        Route::delete('/runs/{run}/verify', [RunController::class, 'unverify']);

        // Both of these read the scoreboard WITHOUT the freeze applied --
        // Api\ScoreboardController::export() calls Leaderboard::
        // getScoreboard() with no frozen flag, and statistics() counts
        // solves straight off the scores table. /contests/{contest}/
        // scoreboard hides exactly that during the freeze, so leaving these
        // two open to teams would hand back the standings the freeze is
        // there to withhold. Same reasoning as the admin-only ICPC report
        // in routes/frontend_api_icpc.php: a standings artifact belongs to
        // whoever is running the contest.
        Route::get('/contests/{contest}/scoreboard/export', [ScoreboardController::class, 'export']);
        Route::get('/contests/{contest}/statistics', [ScoreboardController::class, 'statistics']);
    });

    // Admin only: the lifecycle of the event itself. Creating, editing,
    // deleting and activating a contest is already admin-only on the web
    // side (the `['auth', 'admin']` backend group in routes/web.php), and
    // there is no reason the token API should be the looser door -- a judge
    // judges the contest they were given, they do not end it.
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('contests', ContestController::class)->only(['store', 'update', 'destroy']);
        Route::post('/contests/{contest}/activate', [ContestController::class, 'activate']);
        Route::post('/contests/{contest}/deactivate', [ContestController::class, 'deactivate']);
        // Issue #189 -- revelar o placar final. E a cerimonia, e por isso
        // fica com o admin junto do resto do ciclo de vida do evento: um
        // juiz julga a prova que lhe deram, nao decide quando o resultado
        // vira publico.
        Route::post('/contests/{contest}/unfreeze', [ContestController::class, 'unfreeze']);
    });
});

// Health check
Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

// OpenAPI spec (see docs/api/openapi.yaml -- issue #52)
Route::get('/openapi.yaml', function () {
    return response(file_get_contents(base_path('docs/api/openapi.yaml')), 200, [
        'Content-Type' => 'application/yaml',
    ]);
});

// Current active contest for timer.
//
// Issue #223 -- o relogio da interface calculava "acabou" e "congelado" com
// o relogio LOCAL, e escondia o aviso de congelamento depois do fim,
// enquanto o servidor mantinha o placar congelado. Os dois discordavam na
// hora exata em que a discordancia custa caro: a cerimonia.
//
// O que faltava aqui para a interface poder parar de adivinhar:
//
//  - `competition()`: sem o scope, o contest de TREINO do #43 podia ser
//    escolhido como "o evento", e o relogio da prova mostraria o de treino.
//    Mesmo defeito que o #225 acabou de consertar nos atalhos legados.
//  - `is_ended`: acabar e congelar sao coisas diferentes desde o #189 -- a
//    prova termina e o placar continua congelado ate alguem revelar.
//  - `is_unfrozen`: `is_frozen: false` sozinho nao distingue "nunca
//    congelou" de "foi revelado", e a cerimonia e exatamente a segunda.
//  - `is_finalized`: o estado do #202 nao aparecia em lugar nenhum da
//    superficie que a interface le.
Route::get('/contest/current', function () {
    $contest = Contest::query()->competition()->where('is_active', true)->first();

    if (! $contest) {
        // Atencao, quem consome: isto sai como `{}` e nao como `null` --
        // response()->json(null) serializa assim. E `{}` e TRUTHY, entao um
        // `if (data)` renderiza um relogio para um contest que nao existe. O
        // componente atual testa `data?.start_time`, que e o jeito certo.
        //
        // Mantido como esta de proposito: o consumidor de hoje lida certo
        // com este formato, e trocar a forma do fio por elegancia quebraria
        // quem o le. A armadilha esta fixada em
        // CurrentContestStateTest::test_with_no_active_competition_the_body_is_an_empty_object.
        return response()->json(null);
    }

    return response()->json([
        'id' => $contest->id,
        'name' => $contest->name,
        'start_time' => $contest->start_time?->toIso8601String(),
        'duration' => $contest->duration,
        'end_time' => $contest->end_time?->toIso8601String(),
        'server_time' => now()->toIso8601String(),
        'freeze_time' => $contest->getRawOriginal('freeze_time') ?? 60,
        'is_running' => $contest->isRunning(),
        'is_frozen' => $contest->isFrozen(),
        'is_ended' => $contest->end_time !== null && now()->gt($contest->end_time),
        'is_unfrozen' => $contest->isUnfrozen(),
        'unfrozen_at' => $contest->unfrozen_at?->toIso8601String(),
        'is_finalized' => $contest->isFinalized(),
        'finalized_at' => $contest->finalized_at?->toIso8601String(),
        // O instante do fim vem do servidor porque o cliente nao tem como
        // calcula-lo: desde o #198 ele inclui os intervalos removidos da
        // prova, e desde o #225 pode ter sido encurtado por um encerramento
        // antecipado. start_time + duration nao basta mais.
        'end_time' => $contest->end_time?->toIso8601String(),
        // E o relogio do servidor, para a interface medir a propria
        // defasagem em vez de confiar no relogio da maquina de quem olha.
        'server_time' => now()->toIso8601String(),
    ]);
});
