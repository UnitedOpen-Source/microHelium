<?php

namespace App\Http\Controllers\Judgehost;

use App\Http\Middleware\AuthenticateJudgehost;
use App\Models\Judgehost;
use App\Models\Run;
use Illuminate\Http\Request;

/**
 * Issue #53 -- "you may only speak about the run you are holding".
 *
 * Shared by every judgehost route that names a run in its URL, because the
 * check has to be identical on all of them: the day one of them forgets it
 * is the day a judge machine can read the whole contest.
 */
trait HoldsRun
{
    protected function judgehost(Request $request): Judgehost
    {
        return $request->attributes->get(AuthenticateJudgehost::ATTRIBUTE);
    }

    /**
     * The caller must be the machine this run is currently leased to.
     *
     * Deliberately not "was ever leased to": once a run is given back or
     * its lease expires, the host that used to hold it loses access to the
     * source and the test data along with the work.
     */
    protected function assertHolds(Request $request, Run $run): Judgehost
    {
        $judgehost = $this->judgehost($request);

        if ($run->judgehost_id === null || (int) $run->judgehost_id !== (int) $judgehost->id) {
            abort(403, 'Esta submissao nao esta atribuida a este judgehost.');
        }

        return $judgehost;
    }
}
