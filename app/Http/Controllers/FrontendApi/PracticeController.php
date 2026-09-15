<?php

namespace App\Http\Controllers\FrontendApi;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\Language;
use App\Models\PracticePublication;
use App\Models\Problem;
use App\Models\Run;
use App\Services\Practice\JudgeExecutorHealth;
use App\Services\Practice\PracticeContest;
use App\Services\Practice\PracticePublisher;
use App\Services\RunSubmissionService;
use App\Support\IdempotencyStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Issue #43 -- real backend for /practice, /practice/problems/{id} and
 * /practice/history (docs/specs/43-practice.md;
 * resources/js/features/Practice.vue).
 *
 * Registered on the web session guard + CSRF, like every other
 * /api/frontend/* surface -- see routes/frontend_api_practice.php.
 *
 * The two read endpoints are public because the library is
 * ("usuário sem login pode ler e recebe convite para entrar"), which is
 * exactly why every response carries no-store: `solved` and `capabilities`
 * are per-viewer, and a shared cache holding one participant's answer for
 * the next is the failure this avoids.
 */
class PracticeController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private PracticeContest $practiceContest,
        private PracticePublisher $publisher,
        private JudgeExecutorHealth $executor,
        private RunSubmissionService $submissions,
    ) {}

    /**
     * GET /api/frontend/practice/problems
     */
    public function index(Request $request): JsonResponse
    {
        $contest = $this->practiceContest->find();

        if (! $contest) {
            // Nothing has ever been published. An empty library is a normal
            // state with its own empty message in the UI, not an error, and
            // a read must never provision the practice contest as a side
            // effect.
            return $this->json($this->emptyPage());
        }

        $search = $this->searchTerm($request);

        $query = PracticePublication::query()
            ->active()
            ->join('problem_bank', 'problem_bank.id', '=', 'practice_publications.problem_bank_id')
            ->join('problems', 'problems.id', '=', 'practice_publications.problem_id')
            ->where('problems.contest_id', $contest->id)
            ->select([
                'practice_publications.id as publication_id',
                'problems.id as problem_id',
                'problems.name',
                // The readable code comes from the bank; problems.short_name
                // is an internal uniqueness token (see PracticePublisher).
                'problem_bank.code as short_name',
                'problem_bank.description',
                'problem_bank.tags',
            ])
            // Stable ordering: name is what a reader scans by, and the
            // publication id breaks ties so page 2 never repeats or skips a
            // row that page 1 already showed.
            ->orderBy('problem_bank.name')
            ->orderBy('practice_publications.id');

        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('problem_bank.name', 'like', '%'.$search.'%')
                    ->orWhere('problem_bank.tags', 'like', '%'.$search.'%');
            });
        }

        $page = $query->paginate(self::PER_PAGE);
        $solved = $this->solvedProblemIds($request, $contest, collect($page->items())->pluck('problem_id')->all());

        $items = collect($page->items())->map(fn ($row) => [
            'id' => (int) $row->problem_id,
            'short_name' => (string) ($row->short_name ?: 'P'.$row->problem_id),
            'name' => (string) $row->name,
            'summary' => $this->summary($row->description),
            'tags' => $this->tags($row->tags),
            'solved' => in_array((int) $row->problem_id, $solved, true),
            // "Estatísticas opcionais [...] stats:null quando não
            // implementado ou suprimido por privacidade/baixa amostragem."
            // The spec leaves the minimum-sample policy open ("stats ficam
            // null até política definida"), so null is the honest answer
            // here rather than a number nobody has agreed the meaning of.
            'stats' => null,
        ])->all();

        return $this->json(['items' => $items, 'meta' => $this->meta($page)]);
    }

    /**
     * GET /api/frontend/practice/problems/{problem}
     */
    public function show(Request $request, int $problem): JsonResponse
    {
        $publication = $this->activePublicationFor($problem);

        if (! $publication) {
            // Covers "never published", "does not exist" and "withdrawn"
            // alike: an unpublished entry must not be reachable by id
            // either.
            abort(404, 'Este problema nao esta disponivel no Treino Livre.');
        }

        $snapshot = $publication->problem;
        $bank = $publication->problemBank;
        $contest = $snapshot->contest;

        $user = $request->user();
        $unavailableReason = $this->submitUnavailableReason($user);

        return $this->json([
            'problem' => [
                'id' => (int) $snapshot->id,
                'short_name' => (string) ($bank->code ?: 'P'.$bank->id),
                'name' => (string) $snapshot->name,
                'statement' => (string) $snapshot->description,
                'examples' => $this->examples($bank),
                'time_limit_ms' => (int) $snapshot->time_limit * 1000,
                'memory_limit_mb' => (int) $snapshot->memory_limit,
                'max_source_bytes' => $this->maxSourceBytes(),
                'languages' => $this->practiceContest->languages($contest)
                    ->map(fn (Language $language) => [
                        'id' => (int) $language->id,
                        'name' => (string) $language->name,
                    ])->values()->all(),
            ],
            'capabilities' => [
                'can_submit' => $unavailableReason === null,
                'requires_login' => $user === null,
            ],
            'submit_unavailable_reason' => $unavailableReason,
        ]);
    }

    /**
     * POST /api/frontend/practice/problems/{problem}/runs
     */
    public function storeRun(Request $request, int $problem): JsonResponse
    {
        return IdempotencyStore::handle($request, 'practice.runs.store', function () use ($request, $problem) {
            $user = $request->user();

            if (! $user->is_enabled) {
                abort(403, 'Sua conta nao esta habilitada para enviar solucoes.');
            }

            // Re-checked inside the idempotent callback, not only on the GET
            // that rendered the form: "POST revalida o estado, pois o
            // ambiente pode falhar depois do GET; responder 503 sem criar
            // falsa confirmação ou enfileirar execução insegura."
            if (! $this->executor->isAvailable()) {
                abort(503, $this->executor->publicReason());
            }

            $publication = $this->activePublicationFor($problem);

            if (! $publication) {
                // Withdrawn between the page load and the submit. A 409 says
                // "the thing you were looking at changed", which is what
                // happened, rather than 404's "never existed".
                abort(409, 'Este problema foi retirado do Treino Livre. Recarregue a biblioteca.');
            }

            $snapshot = $publication->problem;
            $contest = $snapshot->contest;

            $validated = Validator::make($request->all(), [
                'language_id' => ['required', 'integer'],
                'source' => ['required', 'string'],
            ], [], [
                'language_id' => 'linguagem',
                'source' => 'codigo-fonte',
            ])->validate();

            $language = $this->practiceContest->languages($contest)
                ->firstWhere('id', (int) $validated['language_id']);

            if (! $language) {
                throw ValidationException::withMessages([
                    'language_id' => 'Linguagem indisponivel para o Treino Livre.',
                ]);
            }

            $source = $validated['source'];

            if (! mb_check_encoding($source, 'UTF-8')) {
                throw ValidationException::withMessages([
                    'source' => 'O codigo precisa estar em UTF-8 valido.',
                ]);
            }

            if (trim($source) === '') {
                throw ValidationException::withMessages([
                    'source' => 'Envie o codigo da sua solucao.',
                ]);
            }

            // Bytes, not characters: the limit is about what gets written to
            // disk and handed to a compiler, and the client checks the same
            // way (new TextEncoder().encode(...).length).
            if (strlen($source) > $this->maxSourceBytes()) {
                throw ValidationException::withMessages([
                    'source' => 'O codigo ultrapassa o tamanho permitido de '.$this->maxSourceBytes().' bytes.',
                ]);
            }

            $run = $this->submissions->submit(
                contest: $contest,
                siteId: $this->practiceContest->site($contest)->id,
                user: $user,
                problem: $snapshot,
                language: $language,
                filename: $this->submissions->filenameFor($language),
                source: $source,
                // Practice deliberately accepts an identical resubmission --
                // retrying the same code after a judge failure is legitimate
                // training behaviour. The accidental double-submit is caught
                // by the Idempotency-Key wrapping this whole callback.
                rejectDuplicateSource: false,
            );

            return response()->json([
                'data' => [
                    'id' => (int) $run->id,
                    'status' => 'pending',
                ],
            ], 202);
        });
    }

    /**
     * GET /api/frontend/practice/history
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $contest = $this->practiceContest->find();

        if (! $contest) {
            return $this->json($this->emptyPage());
        }

        // Scoped to the actor and the practice contest, always. A user_id
        // from the query string is never consulted: "filtrar SEMPRE pelo ator
        // e concurso de prática; não aceitar user_id por query."
        $page = Run::query()
            ->where('contest_id', $contest->id)
            ->where('user_id', $user->user_id)
            ->with(['problem', 'language', 'answer'])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        $items = collect($page->items())->map(function (Run $run) use ($contest, $request) {
            // Issue #138. A practice contest has no jury on standby, so
            // verification_required is false here in every realistic
            // installation and this branch never fires -- it is written
            // anyway because "this code path cannot happen" is how the
            // dead answer2_id columns got there in the first place. If an
            // operator does turn the gate on for practice, the history must
            // honour it like every other team-facing list.
            $withheld = $contest->verification_required
                && $run->verified_at === null
                && ! Run::viewerSeesWithheldVerdicts($request->user());

            $verdict = $withheld ? null : $run->answer?->short_name;

            return [
                'id' => (int) $run->id,
                'problem_name' => (string) ($run->problem?->name ?? 'Problema removido'),
                'language_name' => (string) ($run->language?->name ?? 'Linguagem removida'),
                'status' => $withheld ? 'judging' : (string) $run->status,
                'verdict' => $verdict,
                'created_at' => $run->created_at?->toIso8601String(),
                'recovery_message' => $this->recoveryMessage($verdict),
                // "detail_url só pode apontar para detalhe que já autorize
                // prática e a fonte privada; enquanto não implementado, null."
                'detail_url' => null,
            ];
        })->all();

        return $this->json(['items' => $items, 'meta' => $this->meta($page)]);
    }

    /**
     * A CS verdict means the judge failed, not that the answer was wrong --
     * the history has to say which: "histórico distingue indisponibilidade
     * técnica de resposta incorreta [...] sem penalizar equipe por erro
     * operacional."
     */
    private function recoveryMessage(?string $verdict): ?string
    {
        if ($verdict !== 'CS') {
            return null;
        }

        return 'Este envio nao pode ser julgado por um problema tecnico do ambiente, '
            .'nao por erro na sua solucao. Voce pode enviar novamente.';
    }

    private function activePublicationFor(int $problemId): ?PracticePublication
    {
        return PracticePublication::query()
            ->active()
            ->where('problem_id', $problemId)
            ->with(['problem.contest', 'problemBank'])
            ->latest('published_at')
            ->first();
    }

    private function submitUnavailableReason(?object $user): ?string
    {
        if ($user === null) {
            return 'Entre na sua conta para enviar solucoes de treino.';
        }

        if (! $user->is_enabled) {
            return 'Sua conta nao esta habilitada para enviar solucoes.';
        }

        return $this->executor->publicReason();
    }

    private function solvedProblemIds(Request $request, Contest $contest, array $problemIds): array
    {
        $user = $request->user();

        if (! $user || $problemIds === []) {
            return [];
        }

        return Run::query()
            ->where('contest_id', $contest->id)
            ->where('user_id', $user->user_id)
            ->whereIn('problem_id', $problemIds)
            ->whereHas('answer', fn ($query) => $query->where('is_accepted', true))
            // Issue #138: a green "resolvido" tick is the accepted verdict,
            // just drawn differently, so it waits for the same release.
            ->when(
                $contest->verification_required && ! Run::viewerSeesWithheldVerdicts($user),
                fn ($query) => $query->whereNotNull('verified_at')
            )
            ->distinct()
            ->pluck('problem_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function examples($bank): array
    {
        $input = trim((string) ($bank->sample_input ?? ''));
        $output = trim((string) ($bank->sample_output ?? ''));

        if ($input === '' && $output === '') {
            return [];
        }

        return [['input' => $input, 'output' => $output]];
    }

    private function summary(?string $description): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $description));

        return Str::limit($text, 180);
    }

    private function tags($tags): array
    {
        if (is_string($tags)) {
            $tags = json_decode($tags, true);
        }

        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($tag) => is_scalar($tag) ? trim((string) $tag) : '',
            $tags
        ), fn ($tag) => $tag !== ''));
    }

    private function searchTerm(Request $request): string
    {
        $raw = $request->query('q');

        return is_scalar($raw) ? trim((string) $raw) : '';
    }

    private function maxSourceBytes(): int
    {
        return (int) config('autojudge.max_file_size', 100) * 1024;
    }

    private function emptyPage(): array
    {
        return [
            'items' => [],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => self::PER_PAGE, 'total' => 0],
        ];
    }

    private function meta(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }

    /**
     * Every response here carries per-viewer fields (`solved`,
     * `capabilities`), so none of them may be held in a shared cache.
     */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)
            ->header('Cache-Control', 'private, no-store');
    }
}
