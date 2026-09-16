<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\Problem;
use App\Services\Icpc\IcpcPackageException;
use App\Services\Icpc\IcpcPackageImporter;
use App\Services\ProblemPackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProblemController extends Controller
{
    public function __construct(
        protected ProblemPackageService $packageService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $contestId = $request->get('contest_id');

        // Issue #134: `contest_id` here is a filter the caller chooses, not
        // a boundary -- omit it and this listed every problem in the
        // installation to any authenticated team, including the problem set
        // of an event that had not opened yet. The boundary is the contest's
        // own visibility rule, the same one show() and the web-side listing
        // (#135) apply.
        $problems = Problem::query()
            ->whereHas('contest', fn ($q) => $q->visibleTo(auth()->user()))
            ->when($contestId, fn ($q) => $q->where('contest_id', $contestId))
            ->where('is_fake', false)
            ->with(['contest:id,name'])
            ->withCount('testCases')
            ->orderBy('sort_order')
            ->paginate(20);

        return response()->json($problems);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contest_id' => 'required|exists:contests,id',
            'package' => 'required|file|mimes:zip|max:102400',
            'short_name' => 'nullable|string|max:10',
            'color_name' => 'nullable|string|max:50',
            'color_hex' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'time_limit' => 'nullable|integer|min:1|max:60',
            'memory_limit' => 'nullable|integer|min:16|max:2048',
        ]);

        $contest = Contest::findOrFail($validated['contest_id']);

        $overrides = array_filter([
            'short_name' => $validated['short_name'] ?? null,
            'color_name' => $validated['color_name'] ?? null,
            'color_hex' => $validated['color_hex'] ?? null,
            'time_limit' => $validated['time_limit'] ?? null,
            'memory_limit' => $validated['memory_limit'] ?? null,
        ]);

        // Issue #200 -- um endpoint, dois formatos.
        //
        // A deteccao e pelo CONTEUDO (existe problem.yaml?) e nao por um
        // campo que quem envia teria que preencher: quem exportou do Polygon
        // nao sabe -- nem deveria precisar saber -- qual dos dois formatos
        // esta plataforma chama de nativo.
        $problem = $this->importPackage($contest, $request->file('package'), $overrides);

        return response()->json($problem->load('testCases'), 201);
    }

    /**
     * Issue #200 -- importa o pacote no formato que ele estiver.
     *
     * O formato da ICPC/Kattis e o que o resto do mundo produz: o ICPC
     * Problem Archive publica nele, o Polygon exporta para ele, e os
     * requisitos de CCS o exigem como piso. Ate aqui, um problema preparado
     * no Polygon precisava ser convertido a mao para a estrutura do BOCA.
     */
    private function importPackage(Contest $contest, $file, array $overrides): Problem
    {
        $extractDir = storage_path('app/temp/icpc_'.uniqid());

        try {
            $zip = new \ZipArchive;

            if ($zip->open($file->getRealPath()) !== true) {
                abort(422, 'Nao foi possivel abrir o arquivo ZIP.');
            }

            @mkdir($extractDir, 0o755, true);
            $zip->extractTo($extractDir);
            $zip->close();

            if (! $this->looksLikeIcpcPackage($extractDir)) {
                // Formato do BOCA: o caminho que ja existia, intacto.
                return $this->packageService->importFromZip($contest, $file, $overrides);
            }

            try {
                return app(IcpcPackageImporter::class)->import($contest, $extractDir, $overrides);
            } catch (IcpcPackageException $e) {
                // 422 e nao 500: um pacote invalido e erro de quem enviou, e
                // a mensagem diz o que esta errado. E para isso que a
                // excecao e propria.
                abort(422, $e->getMessage());
            }
        } finally {
            $this->removeTree($extractDir);
        }
    }

    private function looksLikeIcpcPackage(string $dir): bool
    {
        if (is_file($dir.'/problem.yaml')) {
            return true;
        }

        foreach (glob($dir.'/*', GLOB_ONLYDIR) ?: [] as $candidate) {
            if (is_file($candidate.'/problem.yaml')) {
                return true;
            }
        }

        return false;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/*') ?: [] as $path) {
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * Issue #153 -- this used to `load('testCases')`, and after #134 the
     * competitor half of routes/api.php is exactly where it should be: the
     * teams competing in the contest can reach it. What went out with each
     * row was the case's path on disk plus `input_hash` and `output_hash`,
     * sha256 of the hidden input and of the expected output.
     *
     * A digest is not the file, but it is an oracle for it. Guessing the
     * hidden input blind is hopeless; *verifying* a guess against a known
     * sha256 is a loop, and on a problem with a tight input format (small
     * bounds, rigid layout, an obvious corner case) that loop terminates.
     * `output_hash` confirms the expected answer without solving anything.
     * #134 already closed the larger hole next door -- /problems/{problem}/
     * export handed over input/ and output/ themselves -- and this is the
     * same disclosure in digest form, so it belongs on the same side of the
     * split.
     *
     * The count stays, because a team knowing how many cases a judgement
     * runs is legitimate and carries no digest: JudgeWorkQueue::
     * workPayload() already publishes exactly that to the judgehosts. The
     * key is `test_cases_count` rather than workPayload's `test_case_count`
     * because loadCount() names it after the relation, and index() above has
     * been emitting it under that name all along -- the listing and the
     * detail endpoint should not disagree about what the field is called.
     *
     * Staff who need the rows themselves have
     * GET /problems/{problem}/test-cases, behind role:judge,admin.
     */
    public function show(Problem $problem): JsonResponse
    {
        $this->authorizeContestVisibility($problem->contest);

        $problem->load('contest');
        $problem->loadCount(['testCases', 'runs', 'clarifications']);

        return response()->json($problem);
    }

    /**
     * Issue #153 -- the staff-side replacement for what show() used to
     * carry.
     *
     * Preparing or checking a problem is judge work, and the digests are
     * how you tell whether the cases on this installation are the cases the
     * package shipped; removing them from show() should not mean nobody can
     * read them over the API any more. routes/api.php already states that
     * this controller does no authorization of its own and that the route
     * is the whole of the check, so the gate is `role:judge,admin` on the
     * route rather than an `if` in here -- the same shape #134 gave
     * exportPackage(), and the reason a second URI exists instead of show()
     * quietly returning two different bodies depending on who asked.
     *
     * The visibility call is still made: it is a no-op for admins and
     * judges (Contest::isVisibleTo() lets both through unconditionally), so
     * it costs nothing, and it keeps the action correct if the route is
     * ever regrouped.
     */
    public function testCases(Problem $problem): JsonResponse
    {
        $this->authorizeContestVisibility($problem->contest);

        return response()->json([
            'data' => $problem->testCases()->get(),
        ]);
    }

    public function update(Request $request, Problem $problem): JsonResponse
    {
        $validated = $request->validate([
            'short_name' => 'sometimes|string|max:10',
            'name' => 'sometimes|string|max:200',
            'color_name' => 'nullable|string|max:50',
            'color_hex' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'time_limit' => 'sometimes|integer|min:1|max:60',
            'memory_limit' => 'sometimes|integer|min:16|max:2048',
            'output_limit' => 'sometimes|integer|min:1|max:65536',
            'auto_judge' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $problem->update($validated);

        return response()->json($problem);
    }

    public function destroy(Problem $problem): JsonResponse
    {
        $problem->delete();

        return response()->json(null, 204);
    }

    /**
     * Issue #137: these three calls said Storage::disk('app'), and there is
     * no 'app' disk -- config/filesystems.php defines local, public and s3
     * only, so every one of them threw "Disk [app] does not have a configured
     * driver" in any real deployment. It went unnoticed because the tests
     * called Storage::fake('app'), and faking a disk REGISTERS it: the tests
     * were green against a disk that exists nowhere else.
     *
     * 'local' is what was meant -- its root is storage_path('app'), which is
     * exactly what these relative paths are relative to: ProblemPackageService
     * writes packages under storage_path('app/problems/...') and its
     * exportToZip() writes storage_path("app/{$zipPath}"). The tests that
     * cover these two now write real files there instead of faking a disk, so
     * a wrong disk name fails again instead of being papered over.
     */
    public function download(Problem $problem): StreamedResponse
    {
        // This one is the statement itself. Unscoped, it handed out the PDF
        // for a contest that had not started to anyone who guessed the
        // problem id -- the pre-contest statement leak (issue #134).
        $this->authorizeContestVisibility($problem->contest);

        $path = "problems/{$problem->contest_id}/{$problem->basename}/description/{$problem->description_file}";

        if (! Storage::disk('local')->exists($path)) {
            abort(404, 'Problem description file not found');
        }

        return Storage::disk('local')->download($path);
    }

    public function exportPackage(Problem $problem): StreamedResponse
    {
        $zipPath = $this->packageService->exportToZip($problem);

        return Storage::disk('local')->download($zipPath);
    }
}
