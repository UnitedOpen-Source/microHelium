<?php

namespace App\Http\Controllers\Judgehost;

use App\Http\Middleware\AuthenticateJudgehost;
use App\Models\Judgehost;
use App\Models\Run;
use Illuminate\Http\Request;

/**
 * Issue #53/#123 -- "you may only speak about the claim you are holding".
 *
 * Shared by every judgehost route that names a run in its URL, because the
 * check has to be identical on all of them: the day one of them forgets it
 * is the day a judge machine can read the whole contest.
 *
 * Two things are checked, and the second one was missing until #123.
 *
 * The credential says WHICH MACHINE. That is not enough on its own, because
 * two processes of one machine present the same credential: an agent that
 * hung, was restarted, and woke up later could report a verdict for a run
 * its own replacement was in the middle of judging -- the server accepted
 * it, and then rejected the replacement's correct verdict as a conflict.
 *
 * So the claim token says WHICH CLAIM. It is a fencing token in the usual
 * sense: issued when the run is handed over, carried on every request about
 * it, refused the moment it stops being current -- which a give-back, a
 * lease expiry, or a fresh claim all make it.
 * docs/specs/53-distributed-judging.md asked for exactly this ("DTO de
 * claim inclui ... lease token/expires_at") before any of this was built.
 */
trait HoldsRun
{
    protected function judgehost(Request $request): Judgehost
    {
        return $request->attributes->get(AuthenticateJudgehost::ATTRIBUTE);
    }

    /**
     * The caller must be the machine this run is leased to, holding the
     * token of the claim that leased it.
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

        if (! $this->presentsCurrentClaim($request, $run)) {
            abort(403, 'O token de claim nao corresponde ao claim atual desta submissao.');
        }

        return $judgehost;
    }

    /**
     * A run being judged always carries a token, because claimNext() issues
     * one with the claim. A request without one, or with one from a claim
     * that is over, is a process that has not noticed it was replaced.
     */
    protected function presentsCurrentClaim(Request $request, Run $run): bool
    {
        $current = (string) ($run->claim_token ?? '');
        $presented = (string) ($request->header(AuthenticateJudgehost::CLAIM_TOKEN_HEADER) ?? '');

        if ($current === '' || $presented === '') {
            return false;
        }

        return hash_equals($current, $presented);
    }
}
