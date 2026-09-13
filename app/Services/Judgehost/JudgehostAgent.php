<?php

namespace App\Services\Judgehost;

use App\Services\AutoJudgeService;
use Closure;
use Throwable;

/**
 * Issue #116 -- the loop that runs on a judge machine.
 *
 * Pull only: it asks for work, does it, reports, and asks again. Nothing in
 * the system ever connects to it, which is the premise of #53 -- a judge in
 * a partner institution's rack needs no inbound port.
 *
 * The shape is DOMjudge's, because its reasons are good ones: register at
 * boot so work lost to a crash is released by the only party that knows it
 * was lost, back off when there is nothing to do, and re-register after any
 * transport failure. What is not DOMjudge's is what the agent refuses to
 * do, which is judge a run it is not fully equipped for (#120).
 */
class JudgehostAgent
{
    /** @var Closure(string, string): void */
    private Closure $log;

    private float $backoff;

    public function __construct(
        private JudgehostClient $client,
        private WorkMaterialiser $materialiser,
        private AutoJudgeService $judge,
        ?Closure $log = null,
    ) {
        $this->log = $log ?? static function (): void {};
        $this->backoff = $this->minBackoff();
    }

    /**
     * Announce this host and release whatever it was still holding.
     *
     * @return array{judgehost: array{id: int, name: string}, reclaimed: int, lease_seconds: int}
     */
    public function register(): array
    {
        $registration = $this->client->register();

        $this->say('info', sprintf(
            'Registrado como "%s" (id %d). Runs devolvidos: %d. Lease: %ds.',
            $registration['judgehost']['name'] ?? '?',
            $registration['judgehost']['id'] ?? 0,
            $registration['reclaimed'] ?? 0,
            $registration['lease_seconds'] ?? 0,
        ));

        return $registration;
    }

    /**
     * One turn of the loop.
     *
     * Returns true when work was done, which is also the signal to ask again
     * immediately: a busy queue should not be polled through a backoff.
     */
    public function tick(): bool
    {
        $payload = $this->client->fetchWork();

        if ($payload === null) {
            $this->backOff();

            return false;
        }

        $this->resetBackoff();

        $runId = (int) $payload['run_id'];
        $this->say('info', "Run #{$runId} recebido.");

        $this->judgeOne($runId, $payload);

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function judgeOne(int $runId, array $payload): void
    {
        try {
            $run = $this->materialiser->materialise($payload);
        } catch (Throwable $e) {
            // Not equipped for this run: a language this machine turns out
            // not to have, a custom script it cannot obtain (#120), a
            // transfer that did not match its digest. Handing it straight
            // back is the whole reason give-back exists -- better than
            // holding it until the lease expires, and far better than
            // judging it by rules the problem replaced.
            $this->say('warn', "Run #{$runId} devolvido: {$e->getMessage()}");
            $this->giveBackQuietly($runId);

            return;
        }

        try {
            $verdict = $this->judge->judgeWithoutPersisting($run);
        } catch (Throwable $e) {
            $this->say('error', "Run #{$runId} falhou ao julgar: {$e->getMessage()}");
            $this->giveBackQuietly($runId);

            return;
        } finally {
            $this->materialiser->cleanup($runId);
        }

        $accepted = $this->client->reportResult($runId, $verdict);

        if (! $accepted) {
            // The lease was gone by the time the verdict arrived, so the run
            // belongs to another machine now. Its answer is the one that
            // counts; this one is dropped without complaint.
            $this->say('warn', "Run #{$runId}: o servidor recusou o resultado, o lease ja havia expirado.");

            return;
        }

        $this->say('info', "Run #{$runId} julgado: {$verdict['verdict']}.");
    }

    /**
     * Run until $shouldStop says otherwise.
     *
     * Any transport failure re-registers before carrying on: the connection
     * may have dropped mid-judging, and re-registering is what releases a
     * run this host can no longer finish.
     *
     * @param  Closure(): bool|null  $shouldStop
     */
    public function run(?Closure $shouldStop = null): void
    {
        $shouldStop ??= static fn (): bool => false;

        $this->register();

        while (! $shouldStop()) {
            try {
                $this->tick();
            } catch (Throwable $e) {
                $this->say('error', "Falha ao falar com o servidor: {$e->getMessage()}");
                $this->backOff();

                try {
                    $this->register();
                } catch (Throwable $reregister) {
                    $this->say('error', "Falha ao registrar de novo: {$reregister->getMessage()}");
                }
            }
        }
    }

    private function giveBackQuietly(int $runId): void
    {
        try {
            $this->client->giveBack($runId);
        } catch (Throwable $e) {
            // Already reassigned, or the server is unreachable. The lease
            // expiry on the server covers both (#112), so there is nothing
            // useful left to do here.
            $this->say('warn', "Run #{$runId}: nao foi possivel devolver ({$e->getMessage()}).");
        }
    }

    /**
     * Sleep, then double up to the ceiling.
     *
     * Without this, fifty idle hosts are fifty requests a second against the
     * server for the whole quiet stretch before a contest starts.
     */
    private function backOff(): void
    {
        usleep((int) round($this->backoff * 1_000_000));

        $this->backoff = min($this->backoff * 2, $this->maxBackoff());
    }

    private function resetBackoff(): void
    {
        $this->backoff = $this->minBackoff();
    }

    public function currentBackoff(): float
    {
        return $this->backoff;
    }

    private function minBackoff(): float
    {
        return max(0.05, (float) config('judgehost.agent.poll_min_seconds', 1));
    }

    private function maxBackoff(): float
    {
        return max($this->minBackoff(), (float) config('judgehost.agent.poll_max_seconds', 30));
    }

    private function say(string $level, string $message): void
    {
        ($this->log)($level, $message);
    }
}
