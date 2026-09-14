<?php

namespace App\Http\Controllers\Judgehost;

use App\Http\Controllers\Controller;
use App\Models\ContestLog;
use App\Models\Run;
use App\Services\JudgeWorkQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    /**
     * Issue #125 -- why a machine could not finish a run.
     *
     * A closed set, because the point is for an organiser to be able to
     * tell "nobody here has Kotlin installed" from "the network is
     * dropping test data", and free text does not aggregate.
     */
    public const GIVE_BACK_REASONS = [
        'linguagem_ausente',
        'pacote_indisponivel',
        'sandbox_falhou',
        'transferencia_corrompida',
        'nao_informado',
    ];

    /**
     * After this many give-backs the run stops being an ordinary retry and
     * starts being something a human has to look at. Deliberately small:
     * with a handful of hosts, three refusals means they all refused.
     */
    public const GIVE_BACK_ALERT_AFTER = 3;

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
        $judgehost = $this->assertHolds($request, $run);

        // Issue #125 -- the reason travels with the run, not only into a log
        // file on someone else's machine. Without it a run no host can judge
        // circulates the queue forever and reads as plain `pending` to the
        // organisers: a team with no verdict and nobody with a clue why.
        $data = $request->validate([
            'reason' => ['nullable', 'string', Rule::in(self::GIVE_BACK_REASONS)],
            'detail' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = $data['reason'] ?? 'nao_informado';
        $attempts = (int) $run->give_back_count + 1;

        $run->update([
            'status' => 'pending',
            'judgehost_id' => null,
            'claimed_at' => null,
            // The claim is over, so its token stops being current (#123).
            'claim_token' => null,
            'give_back_reason' => $reason,
            'give_back_count' => $attempts,
        ]);

        $exhausted = $attempts >= self::GIVE_BACK_ALERT_AFTER;

        // Escalated rather than repeated: the first couple of give-backs are
        // ordinary -- a host without that language, a transfer that failed --
        // and logging each at the same level would bury the case that
        // actually needs a human.
        $message = "Run #{$run->run_number} devolvido por {$judgehost->name}: {$reason}"
            .($attempts > 1 ? " ({$attempts}a vez)" : '');

        $context = [
            'run_id' => $run->id,
            'judgehost' => $judgehost->name,
            'reason' => $reason,
            'detail' => $data['detail'] ?? null,
            'attempts' => $attempts,
        ];

        $exhausted
            ? ContestLog::error($run->contest_id, $message.' -- nenhum judgehost conseguiu julgar esta submissao', $context)
            : ContestLog::warning($run->contest_id, $message, $context);

        return response()->json([
            'data' => [
                'run_id' => $run->id,
                'status' => 'pending',
                'reason' => $reason,
                'attempts' => $attempts,
                'needs_attention' => $exhausted,
            ],
        ]);
    }
}
