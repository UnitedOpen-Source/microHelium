<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\Leaderboard;
use App\Models\Problem;
use App\Models\Run;
use App\Services\ContestClock;
use App\Models\Score;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScoreboardController extends Controller
{
    public function index(Request $request, Contest $contest): JsonResponse
    {
        // Issue #134: a scoreboard is a contest's participants and how they
        // are doing. If the contest itself is not visible to this viewer,
        // neither is its standings table -- one rule for every read hanging
        // off /contests/{contest}.
        $this->authorizeContestVisibility($contest);

        // Issue #211 -- este valor era calculado e descartado: o
        // getScoreboard() que o recebia nunca o lia. Agora ele corta de
        // verdade.
        //
        // A isencao deixou de ser so admin e passou a ser a regra ja nomeada
        // em Run::viewerSeesWithheldVerdicts() -- admin, juiz, staff e sede.
        // Um juiz que nao pudesse ver o placar descongelado nao conseguiria
        // fazer o trabalho dele durante a ultima hora, e a lista de "quem e
        // da organizacao" nao pode existir em duas versoes.
        // Issue #276 -- a janela da SEDE de quem pergunta, como na tela web.
        // Sem sede (anonimo, ou conta sem sede), a resposta conservadora:
        // congelado enquanto qualquer sede ainda esconder.
        $frozen = $this->isFrozenForViewer($contest);

        $scoreboard = Leaderboard::getScoreboard($contest->id, $frozen);

        $problems = Problem::where('contest_id', $contest->id)
            ->where('is_fake', false)
            ->orderBy('sort_order')
            ->get(['id', 'short_name', 'name', 'color_name', 'color_hex']);

        return response()->json([
            'contest' => [
                'id' => $contest->id,
                'name' => $contest->name,
                'is_running' => $contest->isRunning(),
                'is_frozen' => $frozen,
                'start_time' => $contest->start_time,
                'duration' => $contest->duration,
                'penalty' => $contest->penalty,
            ],
            'problems' => $problems,
            'scoreboard' => $scoreboard,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Issue #320 -- a decisao de congelamento, num lugar so.
     *
     * Ela estava escrita por extenso em index() e AUSENTE em userScore(),
     * que e o formato exato da armadilha que este repositorio ja documenta
     * em ScoreboardRanking, em Score::reduceCell() e em
     * Run::scopeCountingTowardsScore(): uma regra com duas formulacoes, uma
     * delas vazia. Metodo privado e nao servico porque os dois chamadores
     * sao os dois metodos deste arquivo; se aparecer um terceiro fora dele,
     * ai sim sobe.
     */
    private function isFrozenForViewer(Contest $contest): bool
    {
        $viewer = auth()->user();
        $siteId = $viewer?->site_id !== null ? (int) $viewer->site_id : null;
        $clock = app(ContestClock::class);

        return ! Run::viewerSeesWithheldVerdicts($viewer) && (
            $siteId !== null
                ? $clock->isFrozenFor($contest, $siteId)
                : $clock->isFrozenForAnyone($contest)
        );
    }

    public function userScore(Request $request, Contest $contest): JsonResponse
    {
        $this->authorizeContestVisibility($contest);

        $user = auth()->user();

        $scores = Score::where('contest_id', $contest->id)
            ->where('user_id', $user->user_id)
            ->with('problem:id,short_name,name')
            ->get();

        $leaderboardEntry = Leaderboard::where('contest_id', $contest->id)
            ->where('user_id', $user->user_id)
            ->first();

        // Issue #320 -- durante o congelamento, a posicao vem do placar
        // CONGELADO.
        //
        // `leaderboard.rank` e reescrito por `recalculateRanks()` a cada
        // veredito da prova inteira, inclusive os que o congelamento
        // esconde, e esta rota o devolvia cru: uma equipe consultando
        // /my-score em laco na ultima hora sabia, pelo proprio numero,
        // quantas equipes passaram por ela e quando -- que e a informacao
        // estrategica que o congelamento existe para negar. RN-007 e
        // RF-F12-004 sao P0 no SRS.
        //
        // Devolver a posicao congelada em vez de `null`: ela e exatamente a
        // que o telao, a pagina publica e o feed CLICS mostram no mesmo
        // instante, entao nao ha o que vazar, e a equipe continua com uma
        // resposta util. O custo e um calculo de placar por chamada, que e o
        // custo que /scoreboard ja paga.
        //
        // Os numeros da PROPRIA equipe (`problems`, `attempts`,
        // `solved_time`, `penalty_time`) continuam ao vivo de proposito: a
        // ICPC congela o placar publico e nao os vereditos da propria
        // equipe, e quem retem veredito dela e o portao do #138, que e outro
        // mecanismo e ja esta aplicado. `is_first_solver` NAO e numero
        // proprio -- perder a marca e saber que outra equipe resolveu antes
        // --, entao ele vem do placar congelado junto com a posicao.
        $frozen = $this->isFrozenForViewer($contest);
        $frozenCells = collect();

        if ($frozen) {
            $frozenRow = collect(Leaderboard::getScoreboard($contest->id, true))
                ->first(fn (array $row) => (int) $row['user']->user_id === (int) $user->user_id);

            $rank = $frozenRow !== null ? (int) $frozenRow['rank'] : null;
            $frozenCells = collect($frozenRow['problems'] ?? [])->keyBy('problem_id');
        } else {
            $rank = $leaderboardEntry?->rank;
        }

        return response()->json([
            'rank' => $rank,
            'is_frozen' => $frozen,
            'problems_solved' => $leaderboardEntry?->problems_solved ?? 0,
            'total_time' => $leaderboardEntry?->total_time ?? 0,
            'problems' => $scores->map(fn ($s) => [
                'problem_id' => $s->problem_id,
                'short_name' => $s->problem->short_name,
                'name' => $s->problem->name,
                'attempts' => $s->attempts,
                'is_solved' => $s->is_solved,
                'is_first_solver' => $frozen
                    ? (bool) ($frozenCells[$s->problem_id]['is_first_solver'] ?? false)
                    : $s->is_first_solver,
                'solved_time' => $s->solved_time,
                'penalty_time' => $s->penalty_time,
                'total_time' => $s->getTotalTime(),
            ]),
        ]);
    }

    public function export(Request $request, Contest $contest): JsonResponse
    {
        $format = $request->get('format', 'json');
        $scoreboard = Leaderboard::getScoreboard($contest->id);

        if ($format === 'icpc') {
            return $this->exportIcpc($contest, $scoreboard);
        }

        return response()->json([
            'contest' => $contest->only(['id', 'name', 'start_time', 'duration', 'penalty']),
            'scoreboard' => $scoreboard,
            'exported_at' => now()->toIso8601String(),
        ]);
    }

    protected function exportIcpc(Contest $contest, array $scoreboard): JsonResponse
    {
        $icpcFormat = [];

        foreach ($scoreboard as $entry) {
            $icpcFormat[] = [
                'rank' => $entry['rank'],
                'team_id' => $entry['user']->icpc_id ?? $entry['user']->id,
                'team_name' => $entry['user']->name,
                'solved' => $entry['problems_solved'],
                'time' => $entry['total_time'],
            ];
        }

        return response()->json([
            'contest_id' => $contest->id,
            'contest_name' => $contest->name,
            'results' => $icpcFormat,
        ]);
    }

    public function statistics(Contest $contest): JsonResponse
    {
        $problems = Problem::where('contest_id', $contest->id)
            ->where('is_fake', false)
            ->get();

        $stats = [];

        foreach ($problems as $problem) {
            $scores = Score::where('contest_id', $contest->id)
                ->where('problem_id', $problem->id)
                ->get();

            $totalAttempts = $scores->sum('attempts');
            $solvedCount = $scores->where('is_solved', true)->count();
            $attemptedCount = $scores->count();

            $stats[] = [
                'problem_id' => $problem->id,
                'short_name' => $problem->short_name,
                'name' => $problem->name,
                'color_hex' => $problem->color_hex,
                'total_attempts' => $totalAttempts,
                'solved_count' => $solvedCount,
                'attempted_count' => $attemptedCount,
                'success_rate' => $attemptedCount > 0 ? round($solvedCount / $attemptedCount * 100, 1) : 0,
                'first_solver' => $scores->where('is_first_solver', true)->first()?->user_id,
            ];
        }

        return response()->json([
            'contest_id' => $contest->id,
            'problems' => $stats,
        ]);
    }
}
