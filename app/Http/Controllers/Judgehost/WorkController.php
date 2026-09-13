<?php

namespace App\Http\Controllers\Judgehost;

use App\Http\Controllers\Controller;
use App\Models\Run;
use App\Services\JudgeWorkQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issue #53 -- the surface a judge machine talks to.
 *
 * Pull only. The machine polls; nothing ever connects to it, which is the
 * whole premise: a partner institution's judge needs no inbound port and
 * no hole in their firewall.
 */
class WorkController extends Controller
{
    use HoldsRun;

    public function __construct(private JudgeWorkQueue $queue) {}

    /**
     * POST /api/judgehost/register
     *
     * Returns how many of this host's runs were put back. DOMjudge's idiom,
     * and the reason its judgehosts survive a restart: the agent calls this
     * at boot, on endpoint error, and after any failed fetch, so work it
     * might be holding is released by the only party that knows it was
     * lost.
     */
    public function register(Request $request): JsonResponse
    {
        $judgehost = $this->judgehost($request);

        return response()->json([
            'data' => [
                'judgehost' => ['id' => $judgehost->id, 'name' => $judgehost->name],
                'reclaimed' => $this->queue->giveBack($judgehost),
                'lease_seconds' => (int) config('judgehost.lease_seconds', 600),
            ],
        ]);
    }

    /**
     * POST /api/judgehost/fetch-work
     *
     * 204 when there is nothing, which is the agent's signal to back off.
     */
    public function fetchWork(Request $request): JsonResponse
    {
        $judgehost = $this->judgehost($request);

        // Reaped here rather than on a schedule: the moment a host asks for
        // work is exactly when a run abandoned by a dead host should become
        // available again, and it needs no cron to be running.
        $this->queue->expireStaleLeases();

        $run = $this->queue->claimNext($judgehost);

        if (! $run) {
            return response()->json(['data' => null], 204);
        }

        return response()->json(['data' => $this->queue->workPayload($run)]);
    }

    /**
     * POST /api/remote-judges/v1/runs/{run}/heartbeat
     *
     * Issue #124 -- "still working on it".
     *
     * Without this a judging longer than the lease is reaped mid-flight and
     * handed to another machine, which judges it and is reaped in turn: the
     * run is never finished, only repeatedly abandoned. Raising
     * lease_seconds instead is not the same trade -- the lease is also how
     * long a run stays stuck when a machine really does die, so a lease
     * long enough for the worst judging is a lease too long for a crash.
     * Separating the two is the whole point of a heartbeat.
     *
     * Only claimed_at moves. It cannot change a status or a verdict, so the
     * worst a compromised or confused agent can do with it is keep a run it
     * already holds -- which the lease was granting anyway.
     */
    public function heartbeat(Request $request, Run $run): JsonResponse
    {
        $this->assertHolds($request, $run);

        abort_unless(
            $run->status === 'judging',
            409,
            'Esta submissao nao esta mais em julgamento.'
        );

        $run->forceFill(['claimed_at' => now()])->saveQuietly();

        $leaseSeconds = (int) config('judgehost.lease_seconds', 600);

        return response()->json([
            'data' => [
                'run_id' => $run->id,
                'lease_seconds' => $leaseSeconds,
                'lease_expires_at' => now()->addSeconds($leaseSeconds)->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/judgehost/runs/{run}/give-back
     *
     * For a host that knows it cannot finish a specific run -- a language
     * it turned out not to have, a sandbox that refused to start. Better
     * than holding it until the lease expires.
     */
    public function giveBackRun(Request $request, Run $run): JsonResponse
    {
        $this->assertHolds($request, $run);

        // The claim is over, so its token stops being current (#123).
        $run->update(['status' => 'pending', 'judgehost_id' => null, 'claimed_at' => null, 'claim_token' => null]);

        return response()->json(['data' => ['run_id' => $run->id, 'status' => 'pending']]);
    }
}
