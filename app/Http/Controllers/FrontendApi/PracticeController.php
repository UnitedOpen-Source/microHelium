<?php

namespace App\Http\Controllers\FrontendApi;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\CurriculumOutcome;
use App\Models\Language;
use App\Models\PracticePublication;
use App\Models\Problem;
use App\Models\Run;
use App\Services\Practice\JudgeExecutorHealth;
use App\Services\Practice\PracticeContest;
use App\Services\RunSubmissionService;
use App\Support\IdempotencyStore;
use App\Support\SourceText;
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

    // No PracticePublisher here. It was injected and never read once --
    // found at PHPStan level 4 (property.onlyWritten), which is the one
    // finding at that level that was a real thing rather than the analyser
    // being pedantic. The container was resolving a service on every
    // request to this controller for nothing.
    public function __construct(
        private PracticeContest $practiceContest,
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
                'practice_publications.problem_bank_id',
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

        // Issue #396 -- filtro por código de habilidade, em qualquer
        // currículo (docs/specs/396-curriculos-oficiais.md).
        $skill = $this->skillCode($request);
        if ($skill !== '') {
            $query->whereExists(function ($exists) use ($skill) {
                $exists->selectRaw('1')
                    ->from('problem_bank_outcomes')
                    ->join('curriculum_outcomes', 'curriculum_outcomes.id', '=', 'problem_bank_outcomes.curriculum_outcome_id')
                    ->whereColumn('problem_bank_outcomes.problem_bank_id', 'practice_publications.problem_bank_id')
                    ->where('curriculum_outcomes.code', $skill);
            });
        }

        $page = $query->paginate(self::PER_PAGE);
        $skillCodes = $this->skillCodesByBank(collect($page->items())->pluck('problem_bank_id')->all());
        $solved = $this->solvedProblemIds($request, $contest, collect($page->items())->pluck('problem_id')->all());

        // getAttribute() and not `->short_name`, deliberately. Every one of
        // these comes from the select above -- `problem_bank.code as
        // short_name`, `problems.name`, and the bank's description and tags
        // -- so they are attributes this query put on the model, not columns
        // of practice_publications. Reading them as properties made PHPStan
        // report four undefined properties, and it was right to: declaring
        // them on the model would claim every PracticePublication has them,
        // which is false everywhere except this one query.
        $items = collect($page->items())->map(fn (PracticePublication $row) => [
            'id' => (int) $row->problem_id,
            'short_name' => (string) ($row->getAttribute('short_name') ?: 'P'.$row->problem_id),
            'name' => (string) $row->getAttribute('name'),
            'summary' => $this->summary($row->getAttribute('description')),
            'tags' => $this->tags($row->getAttribute('tags')),
            'skills' => $skillCodes[(int) $row->problem_bank_id] ?? [],
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
                // Issue #396. Metadado de catálogo, não enunciado: mostra as
                // associações atuais do banco, não as do snapshot publicado.
                'skills' => $bank->outcomes()->with('framework')->get()
                    ->map(fn (CurriculumOutcome $outcome) => $outcome->present())
                    ->values()->all(),
                'time_limit_ms' => (int) $snapshot->time_limit * 1000,
                'memory_limit_mb' => (int) $snapshot->memory_limit,
                'max_source_bytes' => $this->maxSourceBytes(),
                'languages' => $this->practiceContest->languages($contest)
                    ->map(fn (Language $language) => [
                        'id' => (int) $language->id,
                        'name' => (string) $language->name,
                        // Issue #390 (P2): tells the page which input to
                        // offer -- the editor, or a file picker filtered to
                        // the language's own extension.
                        'source_kind' => $language->receivesSourceAsFile() ? 'file' : 'text',
                        'accept' => $language->receivesSourceAsFile() ? '.'.$language->getFileExtension() : null,
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

            Validator::make($request->all(), [
                'language_id' => ['required', 'integer'],
            ], [], [
                'language_id' => 'linguagem',
            ])->validate();

            $language = $this->practiceContest->languages($contest)
                ->firstWhere('id', (int) $request->input('language_id'));

            if (! $language) {
                throw ValidationException::withMessages([
                    'language_id' => 'Linguagem indisponivel para o Treino Livre.',
                ]);
            }

            // Issue #390 (P2) -- a linguagem escolhe o canal, e nao o
            // participante: arquivo so para quem tem fonte-arquivo (o `.sb3`
            // do Scratch), editor de texto para o resto, com as regras de
            // antes. Ver docs/specs/390-envio-de-arquivo-no-treino.md.
            [$filename, $source] = $language->receivesSourceAsFile()
                ? $this->fileSource($request, $language)
                : $this->textSource($request, $language);

            $run = $this->submissions->submit(
                contest: $contest,
                siteId: $this->practiceContest->site($contest)->id,
                user: $user,
                problem: $snapshot,
                language: $language,
                filename: $filename,
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
     * The typed-source channel -- every language except the file-source
     * ones, with the rules #43 already had.
     *
     * @return array{0: string, 1: string} filename and bytes
     */
    private function textSource(Request $request, Language $language): array
    {
        // A file for a text language is refused rather than read: opening
        // upload for C or Python is not what #390 asked for, and accepting
        // it silently would be a second, unvalidated way in.
        if ($request->hasFile('source_file')) {
            throw ValidationException::withMessages([
                'source_file' => 'Esta linguagem recebe o codigo pelo editor, e nao por arquivo.',
            ]);
        }

        $validated = Validator::make($request->all(), [
            'source' => ['required', 'string'],
        ], [], [
            'source' => 'codigo-fonte',
        ])->validate();

        $source = $validated['source'];

        // Issue #390 -- the same "is this text?" SubmissionController asks
        // before rendering a source (#268). UTF-8 alone let through a
        // stored ZIP with ASCII content; the NUL byte in its header is what
        // gives it away.
        if (! SourceText::isText($source)) {
            throw ValidationException::withMessages([
                'source' => 'O codigo precisa estar em UTF-8 valido, sem bytes binarios.',
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

        return [$this->submissions->filenameFor($language), $source];
    }

    /**
     * Issue #390 (P2) -- the uploaded-file channel, for a language whose
     * source is a file (Scratch's `.sb3`).
     *
     * Nothing here is new validation: the size rule is the contest upload's
     * (`file|max:<KB>`, SubmitController and Api\RunController), fed by the
     * same number the text channel above uses; the stored name is
     * RunSubmissionService::filenameFor(), so the extension is always the
     * language's and never the client's (#311); and the bytes go to the same
     * RunSubmissionService::submit() a contest run does, which is where
     * `scratch-run --check` turns an invalid project into CE (#268).
     *
     * @return array{0: string, 1: string} filename and bytes
     */
    private function fileSource(Request $request, Language $language): array
    {
        if ($request->filled('source')) {
            throw ValidationException::withMessages([
                'source_file' => 'Esta linguagem recebe o projeto como arquivo .'.$language->getFileExtension().', e nao como texto.',
            ]);
        }

        Validator::make($request->all(), [
            'source_file' => ['required', 'file', 'max:'.Contest::defaultMaxSourceKb()],
        ], [
            'source_file.required' => 'Envie o arquivo .'.$language->getFileExtension().' do seu projeto.',
            'source_file.max' => 'O arquivo ultrapassa o tamanho permitido de '.$this->maxSourceBytes().' bytes.',
        ], [
            'source_file' => 'arquivo do projeto',
        ])->validate();

        $file = $request->file('source_file');
        $source = (string) file_get_contents($file->getRealPath());

        if ($source === '') {
            throw ValidationException::withMessages([
                'source_file' => 'O arquivo enviado esta vazio.',
            ]);
        }

        return [$this->submissions->filenameFor($language, $file->getClientOriginalName()), $source];
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

    /**
     * Issue #396 -- `?skill=EF06CO02`. Só o formato de um código; qualquer
     * outra coisa vira "sem filtro", como `q` malformado.
     */
    private function skillCode(Request $request): string
    {
        $raw = $request->query('skill');
        $code = is_scalar($raw) ? trim((string) $raw) : '';

        return preg_match('/^[A-Za-z0-9._-]{1,32}$/', $code) ? $code : '';
    }

    /**
     * Uma consulta para a página inteira, não uma por item.
     *
     * @param  list<int|string|null>  $bankIds
     * @return array<int, list<string>> códigos por problem_bank_id, na ordem do documento
     */
    private function skillCodesByBank(array $bankIds): array
    {
        if ($bankIds === []) {
            return [];
        }

        $codes = [];
        $rows = CurriculumOutcome::query()
            ->join('problem_bank_outcomes', 'problem_bank_outcomes.curriculum_outcome_id', '=', 'curriculum_outcomes.id')
            ->whereIn('problem_bank_outcomes.problem_bank_id', $bankIds)
            ->orderBy('curriculum_outcomes.curriculum_framework_id')
            ->orderBy('curriculum_outcomes.position')
            ->get(['curriculum_outcomes.code', 'problem_bank_outcomes.problem_bank_id']);
        foreach ($rows as $row) {
            $codes[(int) $row->getAttribute('problem_bank_id')][] = $row->code;
        }

        return $codes;
    }

    private function searchTerm(Request $request): string
    {
        $raw = $request->query('q');

        return is_scalar($raw) ? trim((string) $raw) : '';
    }

    /**
     * Issue #284 -- aqui a constante global e a resposta certa, e nao um
     * esquecimento.
     *
     * A #284 passou os dois caminhos de submissao a honrar
     * `contests.max_file_size`. O Treino Livre nao tem prova: e superficie
     * global por desenho (#43), e nao ha coluna de contest para ler. Le o
     * mesmo padrao que `Contest::maxSourceKb()` usa quando fica sem prova em
     * maos, para os dois lados nao divergirem se o padrao mudar.
     */
    private function maxSourceBytes(): int
    {
        return Contest::defaultMaxSourceKb() * 1024;
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
