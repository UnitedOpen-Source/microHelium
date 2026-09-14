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
            ->when($contestId, fn ($q) => $q->where('contest_id', $contestId))
            // Issue #134: the broadcast branch below had no contest
            // boundary of its own, so with no contest_id filter a team was
            // handed every broadcast_all clarification in the installation
            // -- answers written for another contest, which is how a
            // clarification gives away what is in a problem.
            ->when(! $user->isAdmin() && ! $user->isJudge(), function ($q) use ($user) {
                $q->whereHas('contest', fn ($q) => $q->visibleTo($user));

                $q->where(function ($q) use ($user) {
                    $q->where('user_id', $user->user_id)
                        ->orWhere('status', 'broadcast_all')
                        // broadcast_site is scoped to the clarification's
                        // own site (where the judge answered), not the
                        // viewer's -- a team at another site must not see it.
                        ->orWhere(function ($q) use ($user) {
                            $q->where('status', 'broadcast_site')
                                ->where('site_id', $user->site_id);
                        });
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

        $this->authorizeContestMembership(
            $contest,
            'Voce nao pode enviar clarificacoes para um contest do qual nao participa.'
        );

        // Validate contest is running
        if (! $contest->isRunning()) {
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
        if (! $user->isAdmin() && ! $user->isJudge()) {
            // The broadcast half of this is what index() filters too: a
            // broadcast is public to the contest it was answered in, not to
            // every account on the installation (issue #134).
            $isReadableBroadcast = $clarification->isBroadcast()
                && $clarification->contest?->isVisibleTo($user);

            if ($clarification->user_id !== $user->user_id && ! $isReadableBroadcast) {
                abort(403, 'Unauthorized');
            }
        }

        $clarification->load(['problem', 'user', 'judge']);

        return response()->json($clarification);
    }

    public function answer(Request $request, Clarification $clarification): JsonResponse
    {
        $this->authorizeScopedAccess(
            auth()->user()->contest_id,
            $clarification->contest_id,
            'Voce nao pode responder clarificacoes de outro contest.'
        );

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
            // Contest uses SoftDeletes -- a Clarification can outlive its
            // contest being soft-deleted, so ->contest can resolve to null.
            'answered_time' => $clarification->contest?->getContestTime() ?? 0,
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
            ->when($contestId, fn ($q) => $q->where('contest_id', $contestId))
            ->where('status', 'pending')
            ->with(['problem:id,short_name,name', 'user:user_id,fullname'])
            ->orderBy('created_at')
            ->get();

        return response()->json($clarifications);
    }
}
