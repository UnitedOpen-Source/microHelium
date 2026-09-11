<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Jobs\RunSimilarityCheckJob;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\SimilarityCheck;
use App\Services\Similarity\EligibleRunFinder;
use App\Services\Similarity\SimilarityLanguageMap;
use App\Support\HandlesIdempotency;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backend for issue #42 (docs/specs/42-similarity.md) behind Similarity.vue.
 * Route group already restricts access to ['auth','admin'] (see
 * routes/frontend_api_similarity.php) -- a participant never reaches any
 * method here and gets a plain 403 from the middleware.
 */
class SimilarityController extends Controller
{
    use HandlesIdempotency;

    private const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));

        $problems = Problem::query()
            ->with(['contest:id,name', 'contest.languages'])
            ->orderBy('id')
            ->get()
            ->map(function (Problem $problem) {
                $languages = $problem->contest->languages
                    ->filter(fn (Language $language) => $language->is_active && SimilarityLanguageMap::isSupported($language))
                    ->values()
                    ->map(fn (Language $language) => ['id' => $language->id, 'name' => $language->name]);

                return $languages->isEmpty() ? null : [
                    'id' => $problem->id,
                    'name' => $problem->name,
                    'contest_name' => $problem->contest->name,
                    'languages' => $languages->all(),
                ];
            })
            ->filter()
            ->values();

        $totalChecks = SimilarityCheck::query()->count();
        $checks = SimilarityCheck::query()
            ->with(['problem:id,name', 'language:id,name', 'pairs' => fn ($q) => $q->orderByDesc('similarity_score')->orderByDesc('id')])
            ->with(['pairs.runA.user:user_id,fullname', 'pairs.runB.user:user_id,fullname'])
            ->orderByDesc('id')
            ->forPage($page, self::PER_PAGE)
            ->get();

        return response()->json([
            'data' => [
                'items' => $checks->map(fn (SimilarityCheck $check) => $this->serializeCheck($check))->all(),
                'meta' => [
                    'current_page' => $page,
                    'last_page' => max(1, (int) ceil($totalChecks / self::PER_PAGE)),
                    'total' => $totalChecks,
                ],
                'capabilities' => ['can_start' => true],
                'problems' => $problems->all(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->idempotent($request, 'POST /api/frontend/similarity/checks', function () use ($request) {
            $validated = $request->validate([
                'problem_id' => ['required', 'integer', 'exists:problems,id'],
                'language_id' => ['required', 'integer', 'exists:languages,id'],
                'threshold' => ['required', 'integer', 'min:0', 'max:100'],
            ]);

            $problem = Problem::findOrFail($validated['problem_id']);
            $language = Language::findOrFail($validated['language_id']);

            if ((int) $language->contest_id !== (int) $problem->contest_id) {
                throw ValidationException::withMessages([
                    'language_id' => ['Essa linguagem não pertence ao concurso deste problema.'],
                ]);
            }

            $jplagLanguage = SimilarityLanguageMap::jplagLanguageFor($language);
            if (!$jplagLanguage) {
                throw ValidationException::withMessages([
                    'language_id' => ['Linguagem não suportada pelo analisador de similaridade.'],
                ]);
            }

            $found = app(EligibleRunFinder::class)->find($problem, $language);
            $eligible = $found['eligible'];
            $excluded = $found['excluded'];

            $maxTeams = (int) config('similarity.max_teams_per_job');
            if ($eligible->count() > $maxTeams) {
                throw ValidationException::withMessages([
                    'problem_id' => ["Esta combinação tem mais de {$maxTeams} equipes elegíveis; reduza o escopo antes de comparar."],
                ]);
            }

            if ($eligible->count() < 2) {
                throw ValidationException::withMessages([
                    'language_id' => ['É necessário pelo menos duas equipes com solução aceita neste problema e linguagem.'],
                ]);
            }

            $runIds = $eligible->pluck('id')->sort()->values()->all();
            $snapshot = [
                'run_ids' => $runIds,
                'source_hashes' => $eligible->mapWithKeys(fn (Run $run) => [$run->id => $run->source_hash])->all(),
                'excluded' => $excluded,
            ];

            // The concurrency count, the duplicate-snapshot lookup, and the
            // SimilarityCheck::create() below all have to be atomic
            // together: without a lock, two concurrent requests for the
            // same contest (e.g. two admin tabs, no Idempotency-Key) could
            // both read zero conflicting rows and both insert, since
            // nothing here is a DB-level unique constraint that could
            // catch a genuine INSERT/INSERT race. Scoped per contest_id --
            // the same granularity as the concurrency cap itself.
            try {
                $response = Cache::lock("similarity-check-create:contest:{$problem->contest_id}", 30)
                    ->block((int) config('similarity.lock_wait_seconds', 5), function () use ($problem, $language, $eligible, $excluded, $validated, $runIds, $snapshot) {
                        $maxConcurrent = (int) config('similarity.max_concurrent_per_contest');
                        $runningForContest = SimilarityCheck::query()
                            ->where('contest_id', $problem->contest_id)
                            ->whereIn('status', ['queued', 'running'])
                            ->count();
                        if ($runningForContest >= $maxConcurrent) {
                            return response()->json([
                                'message' => 'Já existem análises em andamento para este concurso. Aguarde a conclusão antes de solicitar outra.',
                            ], 409);
                        }

                        $duplicate = SimilarityCheck::query()
                            ->where('problem_id', $problem->id)
                            ->where('language_id', $language->id)
                            ->whereIn('status', ['queued', 'running'])
                            ->get()
                            ->first(fn (SimilarityCheck $check) => ($check->snapshot['run_ids'] ?? null) === $runIds);
                        if ($duplicate) {
                            return response()->json([
                                'message' => 'Já existe uma análise em andamento para este mesmo conjunto de equipes.',
                                'data' => ['id' => $duplicate->id],
                            ], 409);
                        }

                        $check = SimilarityCheck::create([
                            'user_id' => auth()->id(),
                            'contest_id' => $problem->contest_id,
                            'problem_id' => $problem->id,
                            'language_id' => $language->id,
                            'status' => 'queued',
                            'threshold' => $validated['threshold'],
                            'team_count' => $eligible->count(),
                            'snapshot' => $snapshot,
                            'options' => [
                                'jplag_version' => config('similarity.jplag.version'),
                                'max_pairs' => config('similarity.max_pairs_per_report'),
                                'base_code' => (bool) config('similarity.base_code_path'),
                            ],
                        ]);

                        RunSimilarityCheckJob::dispatch($check);

                        return response()->json(['data' => ['id' => $check->id, 'status' => 'queued']], 202);
                    });
            } catch (LockTimeoutException) {
                return response()->json([
                    'message' => 'Há muitas solicitações simultâneas para este concurso. Tente novamente em instantes.',
                ], 409);
            }

            return $response;
        });
    }

    /**
     * Mirrors Api\RunController::downloadSource()'s exact authorization
     * check (admin/judge or the run's own owner). The route this hangs off
     * is already ['auth','admin']-gated, so the judge/owner branches are
     * currently unreachable in practice -- kept for parity so this stays
     * correct if that gate is ever loosened, per the spec's "participante
     * ... não consegue baixar a fonte pelo URL adivinhado".
     */
    public function downloadSource(Run $run): StreamedResponse
    {
        $user = auth()->user();

        if (!$user->isAdmin() && !$user->isJudge() && $run->user_id !== $user->user_id) {
            abort(403, 'Você não tem permissão para baixar este código-fonte.');
        }

        if (!$run->source_file || !Storage::disk('local')->exists($run->source_file)) {
            abort(404, 'Arquivo de código-fonte não encontrado.');
        }

        return Storage::disk('local')->download($run->source_file, $run->filename);
    }

    private function serializeCheck(SimilarityCheck $check): array
    {
        return [
            'id' => $check->id,
            'problem_name' => $check->problem->name ?? '',
            'language_name' => $check->language->name ?? '',
            'status' => $check->status,
            'team_count' => $check->team_count,
            'created_at' => $check->created_at->toISOString(),
            'threshold' => $check->threshold,
            'error_message' => $check->safeErrorMessage(),
            'pairs' => $check->pairs->map(fn ($pair) => [
                'id' => $pair->id,
                'team_a' => $pair->runA?->user?->fullname ?? 'Equipe removida',
                'team_b' => $pair->runB?->user?->fullname ?? 'Equipe removida',
                'run_id_a' => $pair->run_id_a,
                'run_id_b' => $pair->run_id_b,
                'source_a_url' => "/api/frontend/similarity/runs/{$pair->run_id_a}/source",
                'source_b_url' => "/api/frontend/similarity/runs/{$pair->run_id_b}/source",
                'similarity_score' => (float) $pair->similarity_score,
            ])->all(),
        ];
    }
}
