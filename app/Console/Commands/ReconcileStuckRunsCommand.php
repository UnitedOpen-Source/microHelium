<?php

namespace App\Console\Commands;

use App\Jobs\JudgeRunJob;
use App\Models\Answer;
use App\Models\ContestLog;
use App\Models\Run;
use Illuminate\Console\Command;
use Illuminate\Queue\Events\UniqueJobSkipped;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

/**
 * Watchdog for issue #45: the "Atrasado" (overdue) badge added to the judge
 * screen in #40 is purely visual -- nothing actually re-dispatches or
 * resolves a run that's been pending past its site's max_judge_wait_time.
 * If JudgeRunJob silently fails to enqueue, or a queue worker dies, an
 * overdue run just sits there forever with a red badge no one may notice.
 *
 * Re-dispatches a stuck run once; if it's *still* pending on the next run
 * of this command, gives up and marks it judged with a "CS" (contest
 * stopped / judge error) verdict rather than leaving it silently invisible
 * to the team forever.
 *
 * Issue #312 -- "preso" passou a ser medido a partir da reivindicacao, e
 * nao do envio: `Run::waitingSince()`. Uma run que um judgehost esta
 * julgando agora, com a lease viva, nao esta presa, e encerra-la como `CS`
 * descartava um veredito que o servidor recebia segundos depois (e recusava
 * com 409). O backstop continua inteiro para quem realmente sumiu: a
 * reivindicacao morta envelhece igual, porque `claimed_at` para de ser
 * renovado no instante em que a maquina para.
 *
 * Issue #313 -- a tentativa so e gasta quando alguma coisa foi de fato
 * enfileirada. `JudgeRunJob` e `ShouldBeUnique`, e com o lock tomado o
 * Laravel descarta o dispatch em silencio; contar essa tentativa fazia a
 * passada seguinte desistir de uma run que nunca chegou a ser redespachada.
 *
 * Issue #314 -- e a reivindicacao morta e devolvida antes do redespacho, em
 * vez de se redespachar por cima dela. Sem isso, a guarda de entrada nova do
 * `JudgeRunJob` ("so julgo run em `pending`") recusaria justamente a
 * recuperacao que este comando existe para fazer.
 */
class ReconcileStuckRunsCommand extends Command
{
    protected $signature = 'runs:reconcile-stuck';

    protected $description = 'Re-dispatch or give up on runs stuck pending past their site\'s max_judge_wait_time';

    /**
     * Where the watchdog records that it ran, read by
     * FrontendApi\JudgingHealthController (#191).
     */
    public const LAST_RUN_KEY = 'judging.watchdog.last_run_at';

    public function handle(): int
    {
        $skipped = $this->watchSkippedDispatches();

        // Must match JudgeController::index()'s pending+judging scope for
        // the "Atrasado" badge, or a run stuck in 'judging' (a worker died
        // mid-judge, after AutoJudgeService::judge() already flipped the
        // status) shows the overdue badge forever without this watchdog
        // ever touching it.
        //
        // O escopo continua o mesmo; o que mudou (#312) e o relogio que
        // decide se a run dentro dele esta atrasada.
        $stuckRuns = Run::whereIn('status', ['pending', 'judging'])
            ->with('site:id,max_judge_wait_time')
            ->get()
            ->filter(fn (Run $run) => $run->isOverdue());

        foreach ($stuckRuns as $run) {
            if ($run->reconcile_attempts >= 1) {
                $this->giveUp($run);
            } else {
                $this->attemptRecovery($run, $skipped);
            }
        }

        // Issue #191 -- record that the watchdog ran.
        //
        // Without this there is no way to tell "nothing was stuck" from
        // "the scheduler has not run since Tuesday", and those are opposite
        // situations that look identical from every screen. A stopped
        // scheduler is the failure nobody notices, because its symptom is
        // an absence.
        //
        // The cache and not a column: this is operational liveness, not
        // contest data, and losing it on a cache flush costs one reporting
        // cycle rather than corrupting anything. `forever` because the
        // health screen has to be able to say "last seen four hours ago",
        // which a short TTL would turn back into "never".
        Cache::forever(self::LAST_RUN_KEY, now()->toISOString());

        $this->info(count($stuckRuns) . ' stuck run(s) processed.');

        return self::SUCCESS;
    }

    /**
     * Issue #313 -- o dispatch que nao aconteceu tem de ser visivel.
     *
     * `PendingDispatch::shouldDispatch()` chama `UniqueLock::acquire()` e,
     * no fracasso, apenas emite `UniqueJobSkipped` e devolve `false`:
     * `dispatch()` nao retorna nada que diga "nao enfileirei". Escutar o
     * evento e o unico jeito de saber, e ele existe desde a 11.x.
     *
     * O `PendingDispatch` e destruido no fim do statement `dispatch()`, que
     * e quando `shouldDispatch()` roda -- entao o evento ja chegou aqui
     * quando a linha seguinte le o registro.
     *
     * O listener e registrado por invocacao e escreve num coletor proprio:
     * um listener de uma invocacao anterior, no mesmo processo, escreve no
     * coletor DELA e nao neste.
     */
    private function watchSkippedDispatches(): object
    {
        $skipped = new class
        {
            /** @var array<int, true> */
            public array $runIds = [];
        };

        Event::listen(UniqueJobSkipped::class, function (UniqueJobSkipped $event) use ($skipped) {
            if ($event->job instanceof JudgeRunJob) {
                $skipped->runIds[(int) $event->job->run->id] = true;
            }
        });

        return $skipped;
    }

    /**
     * A unica tentativa de recuperacao do #45: redespacha, e so conta a
     * tentativa se ela realmente foi enfileirada.
     */
    private function attemptRecovery(Run $run, object $skipped): void
    {
        $this->releaseDeadClaim($run);

        unset($skipped->runIds[(int) $run->id]);

        JudgeRunJob::dispatch($run);

        // Issue #313. Lock tomado significa que EXISTE um job desta run em
        // algum lugar -- um worker morto por SIGKILL que nunca chegou a
        // libera-lo, ou a passada anterior ainda dentro dos 300 s do
        // `uniqueFor`. Nos dois casos o argumento e esperar, nao desistir:
        // gastar a tentativa aqui entregava a run ao `giveUp()` cinco
        // minutos depois sem que nada jamais tivesse sido enfileirado.
        if (isset($skipped->runIds[(int) $run->id])) {
            $this->warn("Run #{$run->run_number} (ID: {$run->id}) NOT re-dispatched: unique lock held, attempt not spent.");

            return;
        }

        $run->increment('reconcile_attempts');
        $this->info("Run #{$run->run_number} (ID: {$run->id}) re-dispatched (attempt {$run->reconcile_attempts}).");
    }

    /**
     * Issue #312/#314 -- devolver a reivindicacao antes de redespachar.
     *
     * Chegar aqui em `judging` quer dizer que o julgamento esta aberto ha
     * mais que o limite da sede CONTANDO DA REIVINDICACAO, ou seja: quem
     * segurava a run parou de dar sinal. As mesmas colunas que
     * `JudgeWorkQueue::expireStaleLeases()` limpa sao limpas aqui, pelo
     * mesmo motivo -- uma run que ninguem esta julgando nao pode continuar
     * marcada como arrendada.
     *
     * E e o que torna o redespacho util: a guarda de entrada do
     * `JudgeRunJob` (#314) so julga run em `pending`, entao redespachar uma
     * run deixada em `judging` seria enfileirar um no-op.
     */
    private function releaseDeadClaim(Run $run): void
    {
        if ($run->status !== 'judging') {
            return;
        }

        $run->update([
            'status' => 'pending',
            'judgehost_id' => null,
            'claimed_at' => null,
            'claim_token' => null,
        ]);
    }

    private function giveUp(Run $run): void
    {
        $answer = Answer::where('contest_id', $run->contest_id)->where('short_name', 'CS')->first();

        $run->update([
            'status' => 'judged',
            'answer_id' => $answer?->id,
            // Set like every other finalization path (AutoJudgeService::
            // updateRunWithResult()/handleJudgingError()) so judged_time
            // isn't left null on a row that says status = 'judged'.
            'judged_time' => $run->contest?->getContestTime() ?? 0,
            'auto_judge_result' => 'Reconciler: run stayed pending after a re-dispatch attempt, marked as judge error.',
        ]);

        // Deliberately NOT calling Score::updateScore() here, matching
        // AutoJudgeService::handleJudgingError()'s existing precedent: a CS
        // verdict caused by our own infrastructure failing to judge in time
        // is not the team's fault and shouldn't count against their
        // attempts for this problem.
        ContestLog::error($run->contest_id, "Run #{$run->run_number} auto-marked as judge error by the stuck-run reconciler", [
            'run_id' => $run->id,
        ]);

        $this->warn("Run #{$run->run_number} (ID: {$run->id}) gave up after a retry, marked as judge error.");
    }
}
