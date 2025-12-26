<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\Problem;
use App\Services\ProblemPackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProblemController extends Controller
{
    public function __construct(
        protected ProblemPackageService $packageService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $contestId = $request->get('contest_id');

        $problems = Problem::query()
            ->when($contestId, fn($q) => $q->where('contest_id', $contestId))
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

    public function download(Problem $problem): StreamedResponse
    {
        $path = "problems/{$problem->contest_id}/{$problem->basename}/description/{$problem->description_file}";

        if (!Storage::disk('app')->exists($path)) {
            abort(404, 'Problem description file not found');
        }

        return Storage::disk('app')->download($path);
    }

    public function exportPackage(Problem $problem): StreamedResponse
    {
        $zipPath = $this->packageService->exportToZip($problem);

        return Storage::disk('app')->download($zipPath);
    }
}
