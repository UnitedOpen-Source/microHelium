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
use App\Support\IdempotencyStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    private const ROUTE = 'POST /api/frontend/similarity/checks';
    private const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));

        // Same concurrency condition store() enforces (queued/running count
        // for a contest < the cap), grouped once for every contest instead
        // of re-querying per problem. can_start reflects whether starting a
        // NEW check is actually possible right now for at least one
        // contest -- without this it stayed hardcoded true even while every
        // contest sat at its concurrency cap, letting the UI offer a submit
        // that would predictably 409.
        $maxConcurrent = (int) config('similarity.max_concurrent_per_contest');
        $runningByContest = SimilarityCheck::query()
            ->whereIn('status', ['queued', 'running'])
            ->selectRaw('contest_id, COUNT(*) as running_count')
            ->groupBy('contest_id')
            ->pluck('running_count', 'contest_id');

        $canStart = false;
        $problems = Problem::query()
            ->with(['contest:id,name', 'contest.languages'])
            ->orderBy('id')
            ->get()
            ->map(function (Problem $problem) use ($runningByContest, $maxConcurrent, &$canStart) {
                $languages = $problem->contest->languages
                    ->filter(fn (Language $language) => $language->is_active && SimilarityLanguageMap::isSupported($language))
                    ->values()
                    ->map(fn (Language $language) => ['id' => $language->id, 'name' => $language->name]);

                if ($languages->isEmpty()) {
                    return null;
                }

                if (($runningByContest->get($problem->contest_id) ?? 0) < $maxConcurrent) {
                    $canStart = true;
                }

                return [
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
            // A single ->with() call: Builder::with() merges eager-load
            // specs by array_merge(), so a second call adding
            // 'pairs.runA.user'/'pairs.runB.user' would silently overwrite
            // this call's ordering closure for the ancestor 'pairs' key
            // with a plain (unordered) load -- pairs would come back in
            // insertion order instead of sorted by similarity_score desc.
            ->with([
                'problem:id,name',
                'language:id,name',
                'pairs' => fn ($q) => $q->orderByDesc('similarity_score')->orderByDesc('id')
                    ->with(['runA.user:user_id,fullname', 'runB.user:user_id,fullname']),
            ])
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
                'capabilities' => ['can_start' => $canStart],
                'problems' => $problems->all(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return IdempotencyStore::handle($request, self::ROUTE, function () use ($request) {
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

            // index() only ever lists active+supported languages in its
            // dropdown -- without this check, a direct API call bypassing
            // the UI could start an analysis against a language the UI
            // has intentionally hidden as retired.
            if (!$language->is_active) {
                throw ValidationException::withMessages([
                    'language_id' => ['Esta linguagem não está mais ativa.'],
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
            // together. Follows this codebase's own established pattern
            // for exactly this "check-then-insert must be atomic" shape
            // (see SubmitController::store()'s duplicate-submission lock):
            // DB::transaction() + ->lockForUpdate() on the rows that
            // matter, not an application-level cache lock whose
            // correctness would depend on the cache driver actually being
            // cross-process-safe (this repo has a documented history of
            // CACHE_STORE/env mismatches -- see fix/issue-59). Locking
            // every queued/running row for this contest_id (an indexed
            // column, see the migration's ['contest_id','status'] index)
            // takes an InnoDB gap lock on that range in production
            // (MySQL), so a concurrent transaction can't insert a new
            // queued/running row for the same contest until this one
            // commits -- closing the INSERT/INSERT race a plain SELECT
            // can't.
            $outcome = DB::transaction(function () use ($problem, $language, $eligible, $excluded, $validated, $runIds, $snapshot) {
                $lockedChecks = SimilarityCheck::query()
                    ->where('contest_id', $problem->contest_id)
                    ->whereIn('status', ['queued', 'running'])
                    ->lockForUpdate()
                    ->get();

                $maxConcurrent = (int) config('similarity.max_concurrent_per_contest');
                if ($lockedChecks->count() >= $maxConcurrent) {
                    return ['response' => response()->json([
                        'message' => 'Já existem análises em andamento para este concurso. Aguarde a conclusão antes de solicitar outra.',
                    ], 409)];
                }

                $duplicate = $lockedChecks
                    ->where('problem_id', $problem->id)
                    ->where('language_id', $language->id)
                    ->first(fn (SimilarityCheck $check) => ($check->snapshot['run_ids'] ?? null) === $runIds);
                if ($duplicate) {
                    return ['response' => response()->json([
                        'message' => 'Já existe uma análise em andamento para este mesmo conjunto de equipes.',
                        'data' => ['id' => $duplicate->id],
                    ], 409)];
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

                return ['check' => $check];
            });

            if (isset($outcome['response'])) {
                return $outcome['response'];
            }

            $check = $outcome['check'];

            try {
                RunSimilarityCheckJob::dispatch($check);
            } catch (\Throwable $e) {
                // The row already committed (outside this catch, the
                // transaction above is done) -- if enqueueing itself
                // throws (e.g. the queue backend is briefly unreachable),
                // nothing will ever run handle() or failed() for this
                // check, so without this it would stay stuck at 'queued'
                // forever instead of surfacing a clear failure.
                Log::error('Failed to dispatch RunSimilarityCheckJob', ['check_id' => $check->id, 'error' => $e->getMessage()]);
                $check->update(['status' => 'failed', 'safe_error_code' => 'dispatch_failed']);

                return response()->json(['data' => ['id' => $check->id, 'status' => 'failed']], 202);
            }

            return response()->json(['data' => ['id' => $check->id, 'status' => 'queued']], 202);
        });
    }

    /**
     * Uses Controller::authorizeSourceAccess() -- the same check
     * Api\RunController::downloadSource() uses (admin/judge or the run's
     * own owner). The route this hangs off is already ['auth','admin']-
     * gated, so the judge/owner branches are currently unreachable in
     * practice -- kept for parity so this stays correct if that gate is
     * ever loosened, per the spec's "participante ... não consegue baixar
     * a fonte pelo URL adivinhado".
     */
    public function downloadSource(Run $run): StreamedResponse
    {
        $this->authorizeSourceAccess($run, 'Você não tem permissão para baixar este código-fonte.');

        if (!$run->source_file || !Storage::disk('local')->exists($run->source_file)) {
            abort(404, 'Arquivo de código-fonte não encontrado.');
        }

        return Storage::disk('local')->download($run->source_file, $run->filename);
    }

    private function serializeCheck(SimilarityCheck $check): array
    {
        $excluded = $check->snapshot['excluded'] ?? [];

        return [
            'id' => $check->id,
            'problem_name' => $check->problem->name ?? '',
            'language_name' => $check->language->name ?? '',
            'status' => $check->status,
            'team_count' => $check->team_count,
            'created_at' => $check->created_at->toISOString(),
            'threshold' => $check->threshold,
            'error_message' => $check->safeErrorMessage(),
            // Additive fields (not in the original Check contract in
            // docs/specs/42-similarity.md) -- Similarity.vue ignores
            // unknown fields, and the spec itself requires this: "Relatório
            // deve guardar contagens de elegíveis/excluídos... para não
            // sugerir cobertura completa". Only aggregate counts per safe
            // reason code are exposed, never a bare list of user ids.
            'excluded_count' => count($excluded),
            'excluded_reasons' => (object) collect($excluded)->countBy('reason')->all(),
            // JPlag's own -n flag (config('similarity.max_pairs_per_report'),
            // snapshotted per-check in `options.max_pairs`) caps how many
            // qualifying pairs the report can contain. A returned count
            // that reaches that cap can't be told apart from "there were
            // exactly that many" vs "there were more, and the rest were
            // cut" from stored data alone -- flagging it as possibly
            // truncated is more honest than a report that looks complete
            // either way (spec: "nunca truncar silenciosamente").
            'pairs_truncated' => isset($check->options['max_pairs'])
                && $check->pairs->count() >= (int) $check->options['max_pairs'],
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
