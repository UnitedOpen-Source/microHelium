<?php

namespace App\Http\Controllers\Judgehost;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateJudgehost;
use App\Models\Judgehost;
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
     * POST /api/judgehost/runs/{run}/give-back
     *
     * For a host that knows it cannot finish a specific run -- a language
     * it turned out not to have, a sandbox that refused to start. Better
     * than holding it until the lease expires.
     */
    public function giveBackRun(Request $request, Run $run): JsonResponse
    {
        $judgehost = $this->judgehost($request);

        $this->assertHolds($judgehost, $run);

        $run->update(['status' => 'pending', 'judgehost_id' => null, 'claimed_at' => null]);

        return response()->json(['data' => ['run_id' => $run->id, 'status' => 'pending']]);
    }

    /**
     * A host may only speak about a run it is actually holding.
     *
     * DOMjudge does not do this -- its get_files endpoints filter on the
     * testcase or submission id alone, so one judgehost credential reads
     * every hidden test case and every contestant's source in the system.
     * Inheriting that would be inheriting a jury-equivalent capability
     * handed to every judge machine.
     */
    private function assertHolds(Judgehost $judgehost, Run $run): void
    {
        if ($run->judgehost_id !== $judgehost->id) {
            abort(403, 'Esta submissao nao esta atribuida a este judgehost.');
        }
    }

    private function judgehost(Request $request): Judgehost
    {
        return $request->attributes->get(AuthenticateJudgehost::ATTRIBUTE);
    }
}
