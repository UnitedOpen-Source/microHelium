<?php

namespace App\Http\Controllers;

use App\Models\Contest;
use App\Models\Language;
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
            ? $contest->problems()->orderBy('sort_order')->orderBy('short_name')->get()
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
        $languages = Language::where('contest_id', $problem->contest_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('exercises.show', compact('problem', 'languages'));
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
}
