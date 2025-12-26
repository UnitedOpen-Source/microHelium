<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\JudgeRunJob;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RunController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $contestId = $request->get('contest_id');

        $runs = Run::query()
            ->when($contestId, fn($q) => $q->where('contest_id', $contestId))
            ->when(!$user->isAdmin() && !$user->isJudge(), fn($q) => $q->where('user_id', $user->user_id))
            ->with(['problem:id,short_name,name', 'language:id,name', 'answer:id,name,short_name,is_accepted', 'user:user_id,fullname'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($runs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contest_id' => 'required|exists:contests,id',
            'problem_id' => 'required|exists:problems,id',
            'language_id' => 'required|exists:languages,id',
            'source_file' => 'required|file|max:' . config('autojudge.max_file_size', 100),
        ]);

        $user = auth()->user();
        $contest = Contest::findOrFail($validated['contest_id']);
        $problem = Problem::findOrFail($validated['problem_id']);
        $language = Language::findOrFail($validated['language_id']);

        // Validate contest is running
        if (!$contest->isRunning()) {
            return response()->json(['error' => 'Contest is not running'], 422);
        }

        // Validate problem belongs to contest
        if ($problem->contest_id !== $contest->id) {
            return response()->json(['error' => 'Problem does not belong to this contest'], 422);
        }

        // Validate language belongs to contest
        if ($language->contest_id !== $contest->id || !$language->is_active) {
            return response()->json(['error' => 'Language is not available for this contest'], 422);
        }

        $file = $request->file('source_file');
        $sourceHash = hash_file('sha256', $file->path());

        // Check for duplicate submission
        $duplicate = Run::where('contest_id', $contest->id)
            ->where('user_id', $user->user_id)
            ->where('problem_id', $problem->id)
            ->where('source_hash', $sourceHash)
            ->first();

        if ($duplicate) {
            return response()->json([
                'error' => 'Duplicate submission detected',
                'existing_run' => $duplicate->run_number,
            ], 422);
        }

        // Store source file
        $path = $file->store("runs/{$contest->id}/{$user->user_id}");

        // Create run
        $run = Run::create([
            'contest_id' => $contest->id,
            'site_id' => $user->site_id ?? $contest->sites()->first()->id,
            'user_id' => $user->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'run_number' => Run::getNextRunNumber($contest->id, $user->site_id ?? 1),
            'filename' => $file->getClientOriginalName(),
            'source_file' => $path,
            'source_hash' => $sourceHash,
            'contest_time' => $contest->getContestTime(),
            'status' => 'pending',
        ]);

        ContestLog::info($contest->id, "Run #{$run->run_number} submitted", [
            'user_id' => $user->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
        ]);

        // Dispatch auto-judge job if enabled
        if ($problem->auto_judge) {
            JudgeRunJob::dispatch($run);
        }

        return response()->json($run->load(['problem', 'language']), 201);
    }

    public function show(Run $run): JsonResponse
    {
        $user = auth()->user();

        // Check permissions
        if (!$user->isAdmin() && !$user->isJudge() && $run->user_id !== $user->user_id) {
            abort(403, 'Unauthorized');
        }

        $run->load(['problem', 'language', 'answer', 'user', 'judge']);

        return response()->json($run);
    }

    public function downloadSource(Run $run): StreamedResponse
    {
        $user = auth()->user();

        // Check permissions
        if (!$user->isAdmin() && !$user->isJudge() && $run->user_id !== $user->user_id) {
            abort(403, 'Unauthorized');
        }

        $path = $run->source_file;

        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'Source file not found');
        }

        return Storage::disk('local')->download($path, $run->filename);
    }

    public function rejudge(Run $run): JsonResponse
    {
        $run->update([
            'status' => 'pending',
            'answer_id' => null,
            'judge_id' => null,
            'judged_time' => null,
            'auto_judge_ip' => null,
            'auto_judge_start' => null,
            'auto_judge_end' => null,
            'auto_judge_result' => null,
            'auto_judge_stdout' => null,
            'auto_judge_stderr' => null,
        ]);

        if ($run->problem->auto_judge) {
            JudgeRunJob::dispatch($run);
        }

        ContestLog::info($run->contest_id, "Run #{$run->run_number} marked for rejudging", [
            'user_id' => auth()->id(),
            'run_id' => $run->id,
        ]);

        return response()->json(['message' => 'Run marked for rejudging', 'run' => $run]);
    }

    public function judge(Request $request, Run $run): JsonResponse
    {
        $validated = $request->validate([
            'answer_id' => 'required|exists:answers,id',
        ]);

        $run->update([
            'status' => 'judged',
            'answer_id' => $validated['answer_id'],
            'judge_id' => auth()->id(),
            'judge_site_id' => auth()->user()->site_id,
            'judged_time' => $run->contest->getContestTime(),
        ]);

        // Update score
        \App\Models\Score::updateScore($run);

        ContestLog::info($run->contest_id, "Run #{$run->run_number} manually judged", [
            'judge_id' => auth()->id(),
            'answer_id' => $validated['answer_id'],
        ]);

        return response()->json($run->load('answer'));
    }
}