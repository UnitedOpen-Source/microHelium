<?php

namespace App\Http\Controllers;

use App\Models\Answer;
use App\Models\Run;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

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

        if (!$user->isAdmin() && !$user->isJudge() && $run->user_id !== $user->user_id) {
            abort(403, $message);
        }
    }
}