<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Clarification;
use App\Models\Contest;
use App\Models\ContestLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClarificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $contestId = $request->get('contest_id');

        $clarifications = Clarification::query()
            ->when($contestId, fn($q) => $q->where('contest_id', $contestId))
            ->when(!$user->isAdmin() && !$user->isJudge(), function ($q) use ($user) {
                $q->where(function ($q) use ($user) {
                    $q->where('user_id', $user->user_id)
                        ->orWhereIn('status', ['broadcast_site', 'broadcast_all']);
                });
            })
            ->with(['problem:id,short_name,name', 'user:user_id,fullname', 'judge:user_id,fullname'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($clarifications);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contest_id' => 'required|exists:contests,id',
            'problem_id' => 'nullable|exists:problems,id',
            'question' => 'required|string|max:2000',
        ]);

        $user = auth()->user();
        $contest = Contest::findOrFail($validated['contest_id']);

        // Validate contest is running
        if (!$contest->isRunning()) {
            return response()->json(['error' => 'Contest is not running'], 422);
        }

        $clarification = Clarification::create([
            'contest_id' => $contest->id,
            'site_id' => $user->site_id ?? $contest->sites()->first()->id,
            'user_id' => $user->user_id,
            'problem_id' => $validated['problem_id'],
            'clarification_number' => Clarification::getNextClarificationNumber(
                $contest->id,
                $user->site_id ?? 1
            ),
            'question' => $validated['question'],
            'contest_time' => $contest->getContestTime(),
            'status' => 'pending',
        ]);

        ContestLog::info($contest->id, "Clarification #{$clarification->clarification_number} submitted", [
            'user_id' => $user->user_id,
            'problem_id' => $validated['problem_id'],
        ]);

        return response()->json($clarification->load('problem'), 201);
    }

    public function show(Clarification $clarification): JsonResponse
    {
        $user = auth()->user();

        // Check permissions
        if (!$user->isAdmin() && !$user->isJudge()) {
            if ($clarification->user_id !== $user->user_id && !$clarification->isBroadcast()) {
                abort(403, 'Unauthorized');
            }
        }

        $clarification->load(['problem', 'user', 'judge']);

        return response()->json($clarification);
    }

    public function answer(Request $request, Clarification $clarification): JsonResponse
    {
        $validated = $request->validate([
            'answer' => 'required|string|max:2000',
            'broadcast' => 'nullable|in:none,site,all',
        ]);

        $broadcast = $validated['broadcast'] ?? 'none';

        $status = match ($broadcast) {
            'site' => 'broadcast_site',
            'all' => 'broadcast_all',
            default => 'answered',
        };

        $clarification->update([
            'answer' => $validated['answer'],
            'status' => $status,
            'answered_time' => $clarification->contest->getContestTime(),
            'judge_id' => auth()->id(),
            'judge_site_id' => auth()->user()->site_id,
        ]);

        ContestLog::info($clarification->contest_id, "Clarification #{$clarification->clarification_number} answered", [
            'judge_id' => auth()->id(),
            'broadcast' => $broadcast,
        ]);

        return response()->json($clarification);
    }

    public function destroy(Clarification $clarification): JsonResponse
    {
        $clarification->update(['status' => 'deleted']);
        $clarification->delete();

        return response()->json(null, 204);
    }

    public function pending(Request $request): JsonResponse
    {
        $contestId = $request->get('contest_id');

        $clarifications = Clarification::query()
            ->when($contestId, fn($q) => $q->where('contest_id', $contestId))
            ->where('status', 'pending')
            ->with(['problem:id,short_name,name', 'user:user_id,fullname'])
            ->orderBy('created_at')
            ->get();

        return response()->json($clarifications);
    }
}