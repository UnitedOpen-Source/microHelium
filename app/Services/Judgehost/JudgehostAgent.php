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
        private ?MachineCapabilities $machine = null,
    ) {
        $this->machine ??= new MachineCapabilities;
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
        $registration = $this->client->register($this->describeThisMachine());

        $this->say('info', sprintf(
            'Linguagens que esta maquina consegue julgar: %s.',
            $registration['languages'] ? implode(', ', $registration['languages']) : 'nenhuma declarada'
        ));

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
     * Issue #117 -- what this machine can run, found out rather than
     * configured.
     *
     * Done at register, and only there, so a machine that had a runtime
     * installed or removed corrects itself by restarting its agent rather
     * than by anyone remembering to edit a list. A probe that fails takes
     * nothing down with it: an agent that cannot ask which languages exist
     * registers without declaring any, and the server treats that as "can
     * judge anything", which is what agents older than this did.
     *
     * @return array<string, mixed>
     */
    private function describeThisMachine(): array
    {
        $described = array_filter([
            'cpu_count' => $this->machine->cpuCount(),
            'memory_mb' => $this->machine->memoryMb(),
        ], fn ($value) => $value !== null);

        try {
            $described['languages'] = $this->machine->detect($this->client->languages());
        } catch (Throwable $e) {
            $this->say('warn', "Nao foi possivel descobrir as linguagens desta maquina: {$e->getMessage()}");
        }

        // Issue #303 -- a versao vai junto da capacidade.
        //
        // Num `try` proprio de proposito: perder a versao nao pode custar a
        // lista. Se a sonda de versao falhar inteira, este host declara
        // exatamente as mesmas linguagens que declarava antes desta
        // mudanca, e o servidor guarda `null` -- que e a verdade sobre uma
        // versao que ninguem conseguiu ler.
        if (isset($described['languages'])) {
            try {
                $versions = $this->machine->versionsOf($described['languages']);

                if ($versions !== []) {
                    $described['language_versions'] = $versions;
                }
            } catch (Throwable $e) {
                $this->say('warn', "Nao foi possivel descobrir as versoes desta maquina: {$e->getMessage()}");
            }
        }

        return $described;
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

        // Issue #123 -- everything said about this run from here on carries
        // the token of the claim that granted it, and stops carrying it the
        // moment the run is done. A process that hung through its lease
        // holds a token that is no longer current, and the server refuses
        // it rather than letting it overwrite a live judging.
        $this->client->useClaim($payload['claim_token'] ?? null);

        try {
            $this->judgeOne($runId, $payload);
        } finally {
            $this->client->useClaim(null);
        }

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
            $reason = $e instanceof UnjudgeableRun ? $e->reason : 'sandbox_falhou';
            $this->say('warn', "Run #{$runId} devolvido ({$reason}): {$e->getMessage()}");
            $this->giveBackQuietly($runId, $reason, $e->getMessage());

            return;
        }

        try {
            $verdict = $this->judge->judgeWithoutPersisting($run, $this->heartbeatFor($runId, $payload));
        } catch (Throwable $e) {
            $this->say('error', "Run #{$runId} falhou ao julgar: {$e->getMessage()}");
            $this->giveBackQuietly($runId, 'sandbox_falhou', $e->getMessage());

            return;
        } finally {
            $this->materialiser->cleanup($runId);
        }

        // Issue #273 -- a medicao vai junto com o veredito.
        //
        // `recordVerdict()` no servidor ja sabia recebe-la por aqui, e o
        // comentario dele diz por que: "Ler so o estado local deixaria todo
        // veredito remoto sem medicao -- e maquina remota e exatamente o
        // caso que esta issue existe para comparar". O que faltava era o
        // fio: o agente media, e a medicao morria nesta maquina.
        //
        // `slowestCase()` e o caso de teste mais lento do julgamento que
        // acabou de acontecer, em segundos; a coluna e em milissegundos.
        $medido = $this->judge->slowestCase();

        $verdict['measured_wall_ms'] = $medido['wall_seconds'] !== null
            ? (int) round($medido['wall_seconds'] * 1000)
            : null;
        $verdict['measured_cpu_ms'] = $medido['cpu_seconds'] !== null
            ? (int) round($medido['cpu_seconds'] * 1000)
            : null;

        // Issue #392 -- e o carimbo do toolchain com que se julgou.
        //
        // Da MachineCapabilities deste agente, a mesma que declarou as
        // versoes no register: a sonda e memoizada nela, entao isto custa um
        // subprocesso so na primeira vez que a extensao aparece -- e nenhum,
        // se o register ja a sondou. Uma sonda que falha vira null, e o
        // veredito vai do mesmo jeito.
        $extension = (string) ($payload['language']['extension'] ?? '');
        $verdict += (new JudgingToolchain($this->machine))->carimbo($extension);

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
     * Issue #124 -- the callback the judging loop yields to.
     *
     * PHP gives this process no thread to beat from: judging is one
     * blocking loop, and a handler that never yields starves whatever is
     * meant to renew its lease. So AutoJudgeService yields after
     * compilation and after each test case instead, and the longest this
     * agent can go silent is one test case rather than one whole judging.
     *
     * That bounds it, so the lease has to be longer than roughly three
     * times the worst single test case -- the usual "renew at a third of
     * the lease" rule. With the default 600s lease that holds for any time
     * limit up to about 195s, which is far beyond what a contest sets.
     *
     * Rate-limited rather than beating on every test case: a problem with
     * 200 quick cases would otherwise be 200 requests nobody needs.
     *
     * @param  array<string, mixed>  $payload
     */
    private function heartbeatFor(int $runId, array $payload): Closure
    {
        $leaseSeconds = max(1.0, (float) ($payload['lease_seconds'] ?? config('judgehost.lease_seconds', 600)));
        $interval = $leaseSeconds / 3;
        $last = microtime(true);

        return function (string $stage, int $index) use ($runId, $interval, &$last): void {
            $now = microtime(true);

            if ($stage !== 'compiled' && ($now - $last) < $interval) {
                return;
            }

            $last = $now;

            // A false here means the claim is gone. The judging carries on
            // -- stopping it saves nothing, the work is already done -- and
            // the report will be refused, which is correct and already
            // handled.
            if (! $this->client->heartbeat($runId)) {
                $this->say('warn', "Run #{$runId}: o claim expirou durante o julgamento.");
            }
        };
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

    private function giveBackQuietly(int $runId, string $reason = 'nao_informado', ?string $detail = null): void
    {
        try {
            $this->client->giveBack($runId, $reason, $detail);
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
