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
        $awaitingVerification = collect();

        if ($contest) {
            $query = Run::where('contest_id', $contest->id)
                ->with(['problem:id,short_name,name', 'language:id,name', 'user:user_id,fullname,username', 'answer:id,name,short_name,is_accepted', 'site:id,name,max_judge_wait_time', 'verifier:user_id,fullname,username'])
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
                $run->is_overdue = $run->isOverdue();

                return $run;
            });
            $judgedRuns = $runs->where('status', 'judged')->take(50);

            // Issue #138: with the gate on, a judged run whose verdict has
            // not been released is work still outstanding -- the team is
            // sitting there seeing nothing -- so it gets its own list above
            // the archive rather than being one unremarkable row inside
            // "Julgadas Recentemente". With the gate off this stays empty
            // and the section does not render, so the screen is unchanged
            // for a contest that did not ask for verification.
            $awaitingVerification = $contest->verification_required
                ? $runs->where('status', 'judged')->filter(fn (Run $run) => ! $run->isVerified())->values()
                : collect();
        }

        $answers = $contest
            ? Answer::where('contest_id', $contest->id)->orderBy('sort_order')->get()
            : collect();

        return view('judge.runs', compact('contest', 'pendingRuns', 'judgedRuns', 'awaitingVerification', 'answers'));
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

        Score::updateScore($run);

        ContestLog::info($run->contest_id, "Run #{$run->run_number} manually judged", [
            'judge_id' => auth()->id(),
            'answer_id' => $answer->id,
        ]);

        return redirect()->route('judge.runs')->with('success', "Run #{$run->run_number} julgada como {$answer->name}.");
    }

    /**
     * Issue #138 -- release a judged verdict to the team.
     *
     * Lives on this controller because this is where manual judging already
     * lives, and because verifying is the same person's next action after
     * judging by hand. The work itself is Controller::markRunVerified(),
     * shared with Api\RunController::verify() so the web door and the token
     * door cannot drift on what verifying means.
     *
     * Note there is no answer_id in this request, deliberately: a verifier
     * confirms a verdict or rejudges it, never edits it in place. See
     * Controller::markRunVerified() for why that is the right shape.
     */
    public function verify(Request $request, Run $run): RedirectResponse
    {
        $this->authorizeRunAccess($run);

        if ($run->status !== 'judged') {
            return back()->withErrors(['verify' => "Run #{$run->run_number} ainda nao tem veredito para verificar."]);
        }

        $validated = $request->validate([
            'verify_comment' => 'nullable|string|max:2000',
        ]);

        $this->markRunVerified($run, $validated['verify_comment'] ?? null);

        return redirect()->route('judge.runs')->with('success', "Veredito da run #{$run->run_number} liberado para a equipe.");
    }

    /**
     * Issue #138 -- take a released verdict back off the board.
     */
    public function unverify(Run $run): RedirectResponse
    {
        $this->authorizeRunAccess($run);

        $this->markRunUnverified($run);

        return redirect()->route('judge.runs')->with('success', "Verificacao da run #{$run->run_number} revogada; o veredito voltou a ficar oculto para a equipe.");
    }

    private function resolveContest(): ?Contest
    {
        $user = auth()->user();

        if ($user->contest_id) {
            return Contest::find($user->contest_id);
        }

        // Issue #43: the technical practice contest is never "the active
        // contest" of an event (docs/specs/43-practice.md).
        return Contest::query()->competition()->where('is_active', true)->first();
    }
}
