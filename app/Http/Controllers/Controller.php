<?php

namespace App\Http\Controllers;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Run;
use App\Models\Score;
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
     * Issue #138 -- release a judged verdict to the team, and record who
     * released it.
     *
     * Shared by JudgeController::verify() (web) and Api\RunController::
     * verify() (API), the same way judge() is shared, so the two doors
     * cannot drift on what verifying means.
     *
     * Note what this does NOT accept: a new answer_id. DOMjudge's
     * verifyAction writes only verified / jury_member / verify_comment and
     * offers the verifier no way to change the verdict, and that is the
     * behaviour worth copying. A verifier who disagrees rejudges: a rejudge
     * puts the run back through judging and leaves a trail (the run returns
     * to `pending`, a ContestLog entry is written, the next verdict carries
     * its own judge_id). Letting the second pair of eyes overwrite the
     * first pair's answer in place would produce the one artefact a contest
     * appeal cannot work with -- a verdict with two authors and a record of
     * only one.
     *
     * Only a judged run can be verified. Verifying a pending one would
     * leave verified_at set when the verdict finally lands, which is
     * exactly the pre-approval the rejudge reset above exists to prevent.
     */
    protected function markRunVerified(Run $run, ?string $comment): void
    {
        $run->update([
            'verified_at' => now(),
            'verified_by' => auth()->id(),
            'verify_comment' => $comment,
        ]);

        // The verdict only now becomes countable, so the cell has to be
        // rebuilt -- with the run's ORIGINAL contest_time, which is what
        // makes a released verdict land on the scoreboard at the minute the
        // team actually submitted rather than the minute a judge got to it.
        Score::recomputeFor($run);

        ContestLog::info($run->contest_id, "Run #{$run->run_number} verdict verified", [
            'run_id' => $run->id,
            'verified_by' => auth()->id(),
            'answer_id' => $run->answer_id,
        ]);
    }

    /**
     * Issue #138 -- take a verdict back off the board.
     *
     * The undo half of markRunVerified(): a verification given in error has
     * to be revocable, or the only way back is a rejudge that discards a
     * correct verdict. Recomputing here is what withdraws the run from the
     * standings again, attempts and penalty included.
     */
    protected function markRunUnverified(Run $run): void
    {
        $run->update([
            'verified_at' => null,
            'verified_by' => null,
            'verify_comment' => null,
        ]);

        Score::recomputeFor($run);

        ContestLog::info($run->contest_id, "Run #{$run->run_number} verdict verification revoked", [
            'run_id' => $run->id,
            'revoked_by' => auth()->id(),
        ]);
    }

    /**
     * Issue #138 -- hand back a run with its verdict removed when the
     * viewer is not yet entitled to it.
     *
     * Hiding `answer` alone is not hiding the verdict. The audit for this
     * issue found four other attributes on the same row that answer the
     * same question: `answer_id`, `judged_time` and `judge_id` say a
     * verdict exists and who gave it, and `auto_judge_result` / `stdout` /
     * `stderr` frequently contain it in words. `status` is rewritten to
     * `judging` rather than left at `judged` because "judged, verdict
     * unknown" is a state no client models -- and it is also true: the run
     * IS still being evaluated, by the person who has to release it.
     *
     * The verification metadata goes too. A team that can see
     * `verified_at = null` on its own run can tell a withheld verdict from
     * a queue that is merely slow, and DOMjudge is explicit that an
     * unverified judging is simply not there as far as the team is
     * concerned.
     *
     * READ PATHS ONLY. This overwrites attributes on the in-memory model;
     * saving one afterwards would write the mask to the database. Every
     * caller here renders or serialises and then discards.
     */
    protected function maskWithheldVerdict(Run $run): Run
    {
        if ($run->verdictVisibleTo(auth()->user())) {
            return $run;
        }

        // The relations too, when they were eager-loaded: a serialised
        // `answer` object or a `judge` name reinstates everything the
        // attributes below just removed.
        if ($run->relationLoaded('answer')) {
            $run->setRelation('answer', null);
        }

        if ($run->relationLoaded('judge')) {
            $run->setRelation('judge', null);
        }

        if ($run->relationLoaded('verifier')) {
            $run->setRelation('verifier', null);
        }

        $run->answer_id = null;
        $run->status = 'judging';
        $run->judged_time = null;
        $run->judge_id = null;
        $run->judge_site_id = null;
        $run->auto_judge_result = null;
        $run->auto_judge_stdout = null;
        $run->auto_judge_stderr = null;
        $run->verified_at = null;
        $run->verified_by = null;
        $run->verify_comment = null;

        return $run;
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
