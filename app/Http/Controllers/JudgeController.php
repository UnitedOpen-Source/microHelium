<?php

namespace App\Http\Controllers;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Run;
use App\Models\Score;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Manual judging screen for the "judge" and "admin" roles. BOCA's judge/run.php
 * (own runs) and judge/runchief.php (chief judge, all runs) are collapsed into
 * one screen here since there's no per-judge run assignment/locking in the
 * current schema -- any judge/admin can see and judge any pending run in
 * their contest.
 */
class JudgeController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'role:judge,admin']);
    }

    public function index(): View
    {
        $contest = $this->resolveContest();

        $pendingRuns = collect();
        $judgedRuns = collect();

        if ($contest) {
            $runs = Run::where('contest_id', $contest->id)
                ->with(['problem:id,short_name,name', 'language:id,name', 'user:user_id,fullname,username', 'answer:id,name,short_name,is_accepted'])
                ->orderByDesc('created_at')
                ->get();

            $pendingRuns = $runs->whereIn('status', ['pending', 'judging']);
            $judgedRuns = $runs->where('status', 'judged')->take(50);
        }

        $answers = $contest
            ? Answer::where('contest_id', $contest->id)->orderBy('sort_order')->get()
            : collect();

        return view('judge.runs', compact('contest', 'pendingRuns', 'judgedRuns', 'answers'));
    }

    public function judge(Request $request, Run $run): RedirectResponse
    {
        $validated = $request->validate([
            'answer_id' => 'required|exists:answers,id',
        ]);

        $answer = Answer::findOrFail($validated['answer_id']);
        if ($answer->contest_id !== $run->contest_id) {
            abort(422, 'Essa resposta nao pertence ao contest desta submissao.');
        }

        $run->update([
            'status' => 'judged',
            'answer_id' => $answer->id,
            'judge_id' => auth()->id(),
            'judge_site_id' => auth()->user()->site_id,
            'judged_time' => $run->contest->getContestTime(),
        ]);

        Score::updateScore($run);

        ContestLog::info($run->contest_id, "Run #{$run->run_number} manually judged", [
            'judge_id' => auth()->id(),
            'answer_id' => $answer->id,
        ]);

        return redirect()->route('judge.runs')->with('success', "Run #{$run->run_number} julgada como {$answer->name}.");
    }

    private function resolveContest(): ?Contest
    {
        $user = auth()->user();

        if ($user->contest_id) {
            return Contest::find($user->contest_id);
        }

        return Contest::where('is_active', true)->first();
    }
}
