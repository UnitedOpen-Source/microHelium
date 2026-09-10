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
        $user = auth()->user();

        $pendingRuns = collect();
        $judgedRuns = collect();

        if ($contest) {
            $query = Run::where('contest_id', $contest->id)
                ->with(['problem:id,short_name,name', 'language:id,name', 'user:user_id,fullname,username', 'answer:id,name,short_name,is_accepted', 'site:id,name,max_judge_wait_time'])
                ->orderByDesc('created_at');

            // A judge stationed at a site only judges their own site's runs
            // plus whatever other sites were explicitly routed to them
            // (Backend\SiteController's "judging routes"). No site set on
            // the judge, or an admin, keeps the original contest-wide view.
            // $user->site can still resolve to null even with site_id set
            // (e.g. the site was soft-deleted) -- SiteController::destroy()
            // clears site_id on delete, but this is defense in depth.
            if (!$user->isAdmin() && $user->site_id && $user->site) {
                $query->whereIn('site_id', $user->site->routedJudgingSiteIds());
            }

            $runs = $query->get();

            $pendingRuns = $runs->whereIn('status', ['pending', 'judging'])->map(function (Run $run) {
                $waitLimit = $run->site->max_judge_wait_time ?? 900;
                $run->is_overdue = $run->created_at->diffInSeconds(now()) > $waitLimit;

                return $run;
            });
            $judgedRuns = $runs->where('status', 'judged')->take(50);
        }

        $answers = $contest
            ? Answer::where('contest_id', $contest->id)->orderBy('sort_order')->get()
            : collect();

        return view('judge.runs', compact('contest', 'pendingRuns', 'judgedRuns', 'answers'));
    }

    public function judge(Request $request, Run $run): RedirectResponse
    {
        $this->authorizeRunAccess($run);

        if ($run->status === 'judged') {
            return back()->withErrors(['answer_id' => "Run #{$run->run_number} ja foi julgada. Use a API de rejudge para reabrir antes de julgar de novo."]);
        }

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
            // Contest uses SoftDeletes -- a Run can outlive its contest
            // being soft-deleted, so ->contest can resolve to null here.
            'judged_time' => $run->contest?->getContestTime() ?? 0,
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

    /**
     * A judge must only be able to judge runs in their own contest -- the
     * `role:judge,admin` middleware only checks the user's type, not which
     * contest the run being acted on belongs to, so without this a judge
     * from contest A could judge (and thus score) a run in contest B just
     * by guessing/incrementing the run id. Admins are trusted across
     * contests, matching the rest of the admin surface.
     */
    private function authorizeRunAccess(Run $run): void
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return;
        }

        if ($user->contest_id !== $run->contest_id) {
            abort(403, 'Voce nao pode julgar submissoes de outro contest.');
        }
    }
}
