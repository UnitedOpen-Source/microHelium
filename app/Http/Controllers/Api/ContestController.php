<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Answer;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Language;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContestController extends Controller
{
    public function index(): JsonResponse
    {
        // Issue #43: the technical practice contest is excluded from the
        // public selector for everyone, admins included -- it is reached
        // through /practice, not by picking it as an event.
        //
        // Issue #134: the visibility filter used to be a bare
        // `is_public` for anyone who is not an admin, which was wrong in
        // both directions -- a judge could not see the private contest they
        // were judging, and a team could not see its own private contest in
        // the list it is meant to pick from. Contest::scopeVisibleTo() is
        // the same rule show() now enforces, so the listing and the detail
        // endpoint answer the same question.
        $contests = Contest::query()
            ->competition()
            ->visibleTo(auth()->user())
            ->withCount(['problems', 'users', 'runs'])
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($contests);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'start_time' => 'nullable|date',
            'duration' => 'required|integer|min:1|max:10080',
            'freeze_time' => 'required|integer|min:0',
            'penalty' => 'required|integer|min:0|max:120',
            'max_file_size' => 'required|integer|min:1|max:10240',
            'is_public' => 'boolean',
        ]);

        $contest = Contest::create($validated);

        // Create default site
        Site::create([
            'contest_id' => $contest->id,
            'name' => 'Main Site',
            'is_active' => true,
            'permit_logins' => true,
        ]);

        // Create default languages
        foreach (Language::getDefaultLanguages() as $lang) {
            Language::create(array_merge($lang, ['contest_id' => $contest->id]));
        }

        // Create default answers
        foreach (Answer::getDefaultAnswers() as $answer) {
            Answer::create(array_merge($answer, ['contest_id' => $contest->id]));
        }

        return response()->json($contest->load(['sites', 'languages', 'answers']), 201);
    }

    public function show(Contest $contest): JsonResponse
    {
        // Route-model binding hands over whatever numeric id was in the URL.
        // Without this, incrementing that id walked the whole installation:
        // every contest, with its problem list loaded, whatever is_public
        // said and whether or not it had started (issue #134).
        $this->authorizeContestVisibility($contest);

        $contest->load([
            'sites',
            'languages' => fn ($q) => $q->where('is_active', true),
            'answers' => fn ($q) => $q->orderBy('sort_order'),
            'problems' => fn ($q) => $q->orderBy('sort_order'),
        ]);

        $contest->loadCount(['users', 'runs', 'clarifications']);

        return response()->json($contest);
    }

    public function update(Request $request, Contest $contest): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'start_time' => 'nullable|date',
            'duration' => 'sometimes|integer|min:1|max:10080',
            'freeze_time' => 'sometimes|integer|min:0',
            'penalty' => 'sometimes|integer|min:0|max:120',
            'max_file_size' => 'sometimes|integer|min:1|max:10240',
            'is_active' => 'sometimes|boolean',
            'is_public' => 'sometimes|boolean',
        ]);

        $contest->update($validated);

        return response()->json($contest);
    }

    public function destroy(Contest $contest): JsonResponse
    {
        $contest->delete();

        return response()->json(null, 204);
    }

    public function activate(Contest $contest): JsonResponse
    {
        // Issue #43: global activation must never be able to turn the
        // practice contest into the running event.
        if ($contest->is_practice) {
            abort(422, 'O contest tecnico do Treino Livre nao pode ser ativado como competicao.');
        }

        $contest->update(['is_active' => true]);

        return response()->json(['message' => 'Contest activated', 'contest' => $contest]);
    }

    public function deactivate(Contest $contest): JsonResponse
    {
        $contest->update(['is_active' => false]);

        return response()->json(['message' => 'Contest deactivated', 'contest' => $contest]);
    }

    /**
     * Issue #189 -- release the standings. This is the ceremony.
     *
     * Admin only, and irreversible by design: re-freezing after the room
     * has seen the result would be theatre, not a correction. The MOJ
     * refuses to unfreeze before the last site's contest has ended plus a
     * minute, for the same reason the check below exists -- unfreezing
     * while anyone is still competing publishes the answers to a live
     * contest.
     */
    public function unfreeze(Contest $contest): JsonResponse
    {
        if ($contest->isRunning()) {
            abort(422, 'A competicao ainda esta em andamento; revelar o placar agora mostraria o resultado a quem ainda esta competindo.');
        }

        if ($contest->isUnfrozen()) {
            // Idempotent: the button is pressed on a stage, and a second
            // press must not look like a failure.
            return response()->json([
                'message' => 'O placar ja havia sido revelado.',
                'unfrozen_at' => $contest->unfrozen_at?->toISOString(),
            ]);
        }

        $contest->update(['unfrozen_at' => now()]);

        ContestLog::info($contest->id, 'Placar final revelado', [
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Placar final revelado.',
            'unfrozen_at' => $contest->fresh()->unfrozen_at?->toISOString(),
        ]);
    }

    public function status(Contest $contest): JsonResponse
    {
        // Same gate as show(): start_time and duration of an event you may
        // not see are still that event's information.
        $this->authorizeContestVisibility($contest);

        return response()->json([
            'is_active' => $contest->is_active,
            'is_running' => $contest->isRunning(),
            'is_frozen' => $contest->isFrozen(),
            // Issue #189: distinct from `! is_frozen`, which is also true
            // before the window opens. A scoreboard screen needs to know
            // whether the standings were released.
            'is_unfrozen' => $contest->isUnfrozen(),
            'unfrozen_at' => $contest->unfrozen_at?->toISOString(),
            'start_time' => $contest->start_time,
            'end_time' => $contest->end_time,
            'contest_time' => $contest->getContestTime(),
            'duration' => $contest->duration,
            'freeze_time' => $contest->freeze_time,
        ]);
    }
}
