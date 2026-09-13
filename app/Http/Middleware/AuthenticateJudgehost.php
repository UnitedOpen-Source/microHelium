<?php

namespace App\Http\Middleware;

use App\Models\Judgehost;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue #53 -- the judge-machine principal.
 *
 * Same shape as #44's webcast guard: a bearer token looked up by its
 * sha256 against an indexed unique column, no Auth::login(), so a resolved
 * judgehost carries no identity any other guard in the app recognises.
 *
 * The identity comes from the token and nothing else. DOMjudge
 * authenticates every judgehost with one shared password and then reads
 * the hostname out of the request body, so a holder of that password can
 * register as any host and have another host's in-flight work given back
 * to the queue -- that is the weakness this deliberately does not copy.
 *
 * A missing, unknown or disabled token all produce the same 401: a
 * decommissioned machine still holding its credential learns nothing about
 * why it stopped working.
 */
class AuthenticateJudgehost
{
    public const ATTRIBUTE = 'judgehost';

    /**
     * Issue #123 -- the header carrying the fencing token of the claim a
     * request is about.
     *
     * Defined on this class rather than on the HoldsRun trait that enforces
     * it, because PHP does not let a trait constant be read through the
     * trait name: the agent could not reference it, and two copies of the
     * same string is exactly how a protocol constant drifts.
     */
    public const CLAIM_TOKEN_HEADER = 'X-Claim-Token';

    public function handle(Request $request, Closure $next): Response
    {
        $judgehost = Judgehost::authenticate($request->bearerToken());

        if (! $judgehost) {
            abort(401, 'Credencial de judgehost invalida ou ausente.');
        }

        // Liveness, and the clock the lease reaper reads.
        $judgehost->forceFill(['last_seen_at' => now()])->saveQuietly();

        $request->attributes->set(self::ATTRIBUTE, $judgehost);

        return $next($request);
    }
}
