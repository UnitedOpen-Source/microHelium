<?php

namespace App\Http\Controllers;

use App\Models\Contest;
use App\Models\Problem;
use App\Models\Score;

class ProblemController extends Controller
{
    /**
     * List the real (BOCA-schema) problems for the current user's contest,
     * replacing the old Exercise-table listing that had no relation to the
     * actual judging pipeline.
     */
    public function index()
    {
        $contest = $this->resolveContest();

        $problems = $contest
            ? $contest->problems()->orderBy('short_name')->get()
            : collect();

        $solvedProblemIds = collect();
        if ($contest && auth()->check()) {
            $solvedProblemIds = Score::where('contest_id', $contest->id)
                ->where('user_id', auth()->id())
                ->where('is_solved', true)
                ->pluck('problem_id');
        }

        return view('exercises.index', [
            'contest' => $contest,
            'problems' => $problems,
            'solvedProblemIds' => $solvedProblemIds,
        ]);
    }

    public function show(Problem $problem)
    {
        $this->authorizeProblemVisibility($problem);

        return view('exercises.show', compact('problem'));
    }

    /**
     * The active public contest the current user belongs to (falling back to
     * the first active contest so admins/spectators without a contest_id can
     * still browse problems).
     */
    private function resolveContest(): ?Contest
    {
        $user = auth()->user();

        if ($user?->contest_id) {
            return Contest::find($user->contest_id);
        }

        return Contest::where('is_active', true)->first();
    }

    /**
     * index() only ever lists problems from the one contest resolveContest()
     * picks, so it can't leak another contest's problems -- but show() takes
     * a Problem straight from route-model-binding on a route with no auth
     * middleware, so without this a problem from any other (private,
     * inactive, or not-yet-announced) contest was viewable just by
     * guessing/incrementing the numeric id.
     */
    private function authorizeProblemVisibility(Problem $problem): void
    {
        $user = auth()->user();

        if ($user?->isAdmin() || $user?->isJudge()) {
            return;
        }

        $currentContest = $this->resolveContest();
        if ($currentContest && $problem->contest_id === $currentContest->id) {
            return;
        }

        if ($problem->contest->is_public) {
            return;
        }

        abort(404);
    }
}
