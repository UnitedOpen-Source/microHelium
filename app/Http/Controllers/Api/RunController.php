<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\JudgeRunJob;
use App\Models\Answer;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Score;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RunController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $contestId = $request->get('contest_id');

        $runs = Run::query()
            ->when($contestId, fn ($q) => $q->where('contest_id', $contestId))
            ->when(! $user->isAdmin() && ! $user->isJudge(), fn ($q) => $q->where('user_id', $user->user_id))
            ->with(['problem:id,short_name,name', 'language:id,name', 'answer:id,name,short_name,is_accepted', 'user:user_id,fullname', 'contest:id,verification_required'])
            ->orderByDesc('created_at')
            ->paginate(20);

        // Issue #138: the row-level scoping above already narrowed a team to
        // its OWN runs -- and its own withheld verdict is exactly the one
        // this gate exists to hold back, so scoping is not the same as
        // masking. Staff fall through untouched (Run::verdictVisibleTo()).
        $runs->getCollection()->transform(fn (Run $run) => $this->maskWithheldVerdict($run));

        return response()->json($runs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contest_id' => 'required|exists:contests,id',
            'problem_id' => 'required|exists:problems,id',
            'language_id' => 'required|exists:languages,id',
            'source_file' => 'required|file|max:'.config('autojudge.max_file_size', 100),
        ]);

        $user = auth()->user();
        $contest = Contest::findOrFail($validated['contest_id']);
        $problem = Problem::findOrFail($validated['problem_id']);
        $language = Language::findOrFail($validated['language_id']);

        // Issue #134: the three checks below all ask whether the SUBMISSION
        // is coherent -- running contest, problem and language belonging to
        // it -- and none of them asked whether the submitter belongs there.
        // A team from another contest could put a run into this contest's
        // judge queue and onto its scoreboard.
        $this->authorizeContestMembership(
            $contest,
            'Voce nao pode submeter para um contest do qual nao participa.'
        );

        // Validate contest is running
        if (! $contest->isRunning()) {
            return response()->json(['error' => 'Contest is not running'], 422);
        }

        // Validate problem belongs to contest
        if ($problem->contest_id !== $contest->id) {
            return response()->json(['error' => 'Problem does not belong to this contest'], 422);
        }

        // Validate language belongs to contest
        if ($language->contest_id !== $contest->id || ! $language->is_active) {
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

        // Dispatch auto-judge job if enabled (per-language override, falling
        // back to the problem-level default -- see Problem::isAutoJudgeEnabledFor())
        if ($problem->isAutoJudgeEnabledFor($language)) {
            JudgeRunJob::dispatch($run);
        }

        return response()->json($run->load(['problem', 'language']), 201);
    }

    public function show(Run $run): JsonResponse
    {
        $user = auth()->user();

        // Check permissions
        if (! $user->isAdmin() && ! $user->isJudge() && $run->user_id !== $user->user_id) {
            abort(403, 'Unauthorized');
        }

        $run->load(['problem', 'language', 'answer', 'user', 'judge', 'contest']);

        return response()->json($this->maskWithheldVerdict($run));
    }

    public function downloadSource(Run $run): StreamedResponse
    {
        $this->authorizeSourceAccess($run);

        $path = $run->source_file;

        if (! Storage::disk('local')->exists($path)) {
            abort(404, 'Source file not found');
        }

        return Storage::disk('local')->download($path, $run->filename);
    }

    public function rejudge(Run $run): JsonResponse
    {
        $this->authorizeRunAccess($run);

        $run->update([
            'status' => 'pending',
            'answer_id' => null,
            'judge_id' => null,
            'judged_time' => null,
            // Issue #138: a rejudge throws the verdict away, so the
            // signature that released it has to go with it. Leaving
            // verified_at set would mean the NEXT verdict -- produced by a
            // different judging, possibly against different test data --
            // arrives pre-approved, carrying a jury member's name it was
            // never shown to.
            'verified_at' => null,
            'verified_by' => null,
            'verify_comment' => null,
            'auto_judge_ip' => null,
            'auto_judge_start' => null,
            'auto_judge_end' => null,
            'auto_judge_result' => null,
            'auto_judge_stdout' => null,
            'auto_judge_stderr' => null,
        ]);

        if ($run->problem && $run->language && $run->problem->isAutoJudgeEnabledFor($run->language)) {
            JudgeRunJob::dispatch($run);
        }

        ContestLog::info($run->contest_id, "Run #{$run->run_number} marked for rejudging", [
            'user_id' => auth()->id(),
            'run_id' => $run->id,
        ]);

        return response()->json(['message' => 'Run marked for rejudging', 'run' => $run]);
    }

    /**
     * PUT /api/runs/{run}/verify -- issue #138.
     *
     * The DOMjudge verification gate: "If verification is required, a judge
     * inspects the judging. Only after it has been approved (marked as
     * verified) will the result be visible outside the jury interface."
     *
     * Any judge/admin may verify, including the one who judged it -- same as
     * DOMjudge, which puts no separation-of-duty rule on this. The value is
     * in the second look being recorded, not in policing who took it.
     *
     * No answer_id is accepted here; see Controller::markRunVerified().
     */
    public function verify(Request $request, Run $run): JsonResponse
    {
        $this->authorizeRunAccess($run);

        $validated = $request->validate([
            'verify_comment' => 'nullable|string|max:2000',
        ]);

        if (! $run->isJudged()) {
            return response()->json([
                'error' => "Run #{$run->run_number} ainda nao tem veredito para verificar.",
            ], 422);
        }

        $this->markRunVerified($run, $validated['verify_comment'] ?? null);

        return response()->json($run->fresh()->load('answer'));
    }

    /**
     * DELETE /api/runs/{run}/verify -- issue #138, the undo.
     */
    public function unverify(Run $run): JsonResponse
    {
        $this->authorizeRunAccess($run);

        $this->markRunUnverified($run);

        return response()->json($run->fresh()->load('answer'));
    }

    public function judge(Request $request, Run $run): JsonResponse
    {
        $this->authorizeRunAccess($run);

        if ($run->status === 'judged') {
            return response()->json([
                'error' => "Run #{$run->run_number} ja foi julgada. Use a API de rejudge para reabrir antes de julgar de novo.",
            ], 422);
        }

        $validated = $request->validate([
            'answer_id' => 'required|exists:answers,id',
        ]);

        $answer = Answer::findOrFail($validated['answer_id']);
        $this->assertAnswerBelongsToRunsContest($answer, $run);

        $run->update([
            'status' => 'judged',
            'answer_id' => $answer->id,
            'judge_id' => auth()->id(),
            'judge_site_id' => auth()->user()->site_id,
            // Contest uses SoftDeletes -- a Run can outlive its contest
            // being soft-deleted, so ->contest can resolve to null here.
            'judged_time' => $run->contest?->getContestTime() ?? 0,
        ]);

        // Update score
        Score::updateScore($run);

        ContestLog::info($run->contest_id, "Run #{$run->run_number} manually judged", [
            'judge_id' => auth()->id(),
            'answer_id' => $validated['answer_id'],
        ]);

        return response()->json($run->load('answer'));
    }
}
