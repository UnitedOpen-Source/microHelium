<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\Problem;
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

        $problem = $this->packageService->importFromZip(
            $contest,
            $request->file('package'),
            $overrides
        );

        return response()->json($problem->load('testCases'), 201);
    }

    public function show(Problem $problem): JsonResponse
    {
        $this->authorizeContestVisibility($problem->contest);

        $problem->load(['contest', 'testCases']);
        $problem->loadCount(['runs', 'clarifications']);

        return response()->json($problem);
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
