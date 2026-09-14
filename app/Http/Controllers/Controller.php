<?php

namespace App\Http\Controllers;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Run;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * Shared shape for "admin bypasses, everyone else must match a scope id
     * on the target record, otherwise 403" -- previously copy-pasted as
     * JudgeController::authorizeRunAccess(), StaffController::
     * authorizeTaskAccess(), and SiteController::authorizeSiteAccess(),
     * each comparing a different scope column (contest_id, site_id) with
     * an identical three-line body. A `role:` middleware alone (see
     * CheckRole) only checks the user's type, not which contest/site the
     * record being acted on belongs to -- without this, e.g. a judge from
     * contest A could judge a run in contest B just by guessing/
     * incrementing its id.
     */
    protected function authorizeScopedAccess(int|string|null $userScope, int|string|null $resourceScope, string $message): void
    {
        if (auth()->user()->isAdmin()) {
            return;
        }

        if ($userScope !== $resourceScope) {
            abort(403, $message);
        }
    }

    /**
     * Issue #134 -- "this row belongs to a contest you may not see".
     *
     * The vertical fix (who may call the route) left the horizontal half
     * open: Api\ProblemController and Api\ContestController returned any row
     * in the installation to any authenticated team, so a competitor in
     * contest A read contest B's problem set -- statements included, through
     * /problems/{problem}/download -- before B had even started. Api\
     * RunController was already scoping its rows; these were not.
     *
     * 404 rather than 403, matching what issue #135 chose for the same
     * question on the web side (ProblemController::authorizeProblemVisibility
     * aborts 404): a 403 confirms that the id names a real contest, and an
     * unannounced event is something we should not be confirming by
     * increment. The 403s Api\RunController raises are a different question
     * -- "this run is not yours" -- asked about a row the caller already
     * knows exists because it is in their own contest.
     */
    protected function authorizeContestVisibility(?Contest $contest): void
    {
        if (! $contest || ! $contest->isVisibleTo(auth()->user())) {
            abort(404);
        }
    }

    /**
     * Issue #134 -- the write half of the same boundary.
     *
     * Submitting a run or asking a clarification checks that the contest is
     * running and that the problem/language belong to it, but never that the
     * *caller* does, so a team could push a submission into a neighbouring
     * contest's judge queue and onto its scoreboard. Being able to see a
     * public contest is not the same as competing in it, which is why this
     * asks about membership and not about Contest::isVisibleTo().
     */
    protected function authorizeContestMembership(Contest $contest, string $message): void
    {
        $user = auth()->user();

        if ($user->isAdmin() || $user->isJudge()) {
            return;
        }

        if ((int) $user->contest_id === (int) $contest->id) {
            return;
        }

        if ((int) $user->site?->contest_id === (int) $contest->id) {
            return;
        }

        abort(403, $message);
    }

    /**
     * Shared by JudgeController::judge()/rejudge() (web) and
     * Api\RunController::judge()/rejudge() (API) -- both need the exact
     * same "judge from contest A can't touch a run in contest B" check,
     * previously duplicated verbatim between the two.
     */
    protected function authorizeRunAccess(Run $run): void
    {
        $this->authorizeScopedAccess(
            auth()->user()->contest_id,
            $run->contest_id,
            'Voce nao pode julgar submissoes de outro contest.'
        );
    }

    /**
     * Shared by JudgeController::judge() (web) and Api\RunController::judge()
     * (API) -- a run must be judged with an answer/verdict from its own
     * contest's answer set, not one borrowed from an unrelated contest.
     */
    protected function assertAnswerBelongsToRunsContest(Answer $answer, Run $run): void
    {
        if ($answer->contest_id !== $run->contest_id) {
            abort(422, 'Essa resposta nao pertence ao contest desta submissao.');
        }
    }

    /**
     * "Admin/judge, or the run's own owner" -- shared by
     * Api\RunController::downloadSource() and
     * Frontend\SimilarityController::downloadSource() (issue #42), which
     * previously duplicated this exact check verbatim. A future change to
     * who may read a run's source now only needs to happen here.
     */
    protected function authorizeSourceAccess(Run $run, string $message = 'Unauthorized'): void
    {
        $user = auth()->user();

        if (! $user->isAdmin() && ! $user->isJudge() && $run->user_id !== $user->user_id) {
            abort(403, $message);
        }
    }
}
