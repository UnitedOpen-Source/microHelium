<?php

namespace App\Jobs;

use App\Models\Run;
use App\Models\ContestLog;
use App\Services\AutoJudgeService;
use App\Services\Judgehost\SandboxPreflight;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ShouldBeUnique (keyed on the run id, held for $uniqueFor) so
 * runs:reconcile-stuck's watchdog (issue #45) re-dispatching a run whose
 * original job just hasn't been picked up yet (queue backlog, not a lost
 * job) can't result in two workers judging the same run concurrently.
 */
class JudgeRunJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public int $backoff = 10;

    /** Matches $timeout -- the lock can't outlive the longest a legitimate judge attempt should take. */
    public int $uniqueFor = 300;

    public function __construct(
        public Run $run
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->run->id;
    }

    public function handle(AutoJudgeService $judgeService, SandboxPreflight $preflight): void
    {
        // Issue #314 -- so `pending` e julgavel por aqui.
        //
        // Era `status === 'judged'`, e a guarda funcionava: o que estava
        // incompleto era o conjunto de estados que ela reconhecia. Uma run
        // em `judging` JA FOI REIVINDICADA -- por um judgehost remoto
        // (`JudgeWorkQueue::claimNext()`) ou pelo daemon local
        // (`claimNextLocally()`, #126), nos dois casos sob lock de linha --
        // e passava reto por aqui para ser julgada uma segunda vez, em
        // paralelo com a primeira. O `ShouldBeUnique` nao cobre isso: ele e
        // por dispatch NA FILA, e nenhum dos dois caminhos de reivindicacao
        // passa pela fila.
        //
        // Os dois julgamentos nem sempre concordam (#302 mediu imagens com
        // conjuntos de linguagens diferentes; TLE discorda por disputa de
        // CPU), e quem gravasse por ultimo ganhava -- sem nada no log
        // dizendo que houve duas apuracoes.
        //
        // Isto estreita a janela, nao a fecha: a run pode mudar de estado
        // DURANTE o julgamento. Quem fecha essa parte e a guarda de saida
        // do `AutoJudgeService::recordVerdict()`, sob `lockForUpdate`.
        //
        // O watchdog do #45 continua recuperando run perdida: ele devolve a
        // reivindicacao morta para `pending` ANTES de redespachar
        // (`ReconcileStuckRunsCommand::releaseDeadClaim()`), que e a mesma
        // coisa que `expireStaleLeases()` faz com a lease vencida.
        if ($this->run->status !== 'pending') {
            return;
        }

        // Issue #193 -- the jury has this problem on hold, so the run waits
        // rather than collecting a verdict the problem itself is wrong
        // about.
        //
        // This job is dispatched straight from Api\RunController::store()
        // and from rejudge, without going through JudgeWorkQueue, so the
        // gate in the queue does not cover it: a fresh submission to a
        // paused problem would be judged immediately. Leaving the run
        // `pending` is what makes unpausing pick it up -- releasing the
        // pause re-dispatches, and runs:reconcile-stuck (#45) is the
        // backstop if nobody does.
        if ($this->run->problem?->isJudgingPaused()) {
            return;
        }

        // Issue #282 -- o outro caminho que julga, e o que o compose de dev
        // usa.
        //
        // `autojudge:start` recusa subir numa maquina incapaz de confinar,
        // mas o compose de dev nao tem esse servico: ele roda `queue:work`,
        // e o julgamento chega por aqui. Sem esta guarda, a guarda do daemon
        // nao cobriria justamente a pilha onde o problema aparece.
        //
        // DEPOIS das duas checagens acima, e nao antes. Colocada no topo,
        // ela reverteria para `pending` uma run JA JULGADA que fosse
        // redespachada -- o watchdog do #45 redespacha -- e apagaria o
        // resultado real trocando por uma mensagem de recusa. A ordem aqui e
        // "isto ainda precisa de veredito?" antes de "esta maquina pode
        // dar um?".
        //
        // A run fica `pending` de proposito: consertada a maquina,
        // `runs:reconcile-stuck` a pega de novo. Marcar como julgada
        // gravaria um veredito que ninguem apurou.
        //
        // E `auto_judge_result` recebe o motivo, porque a issue nasceu do
        // silencio: o envio tem que DIZER por que nao sera julgado.
        if ($blocker = $preflight->blocker()) {
            $this->recordSandboxRefusal($blocker['reason']);

            return;
        }

        $judgeService->judge($this->run);
    }

    /**
     * O motivo fica em tres lugares, e nenhum e redundante: na run, para
     * quem abre o envio; no log da prova, para quem investiga depois; e no
     * log da aplicacao, para quem esta olhando o terminal agora.
     */
    private function recordSandboxRefusal(string $reason): void
    {
        $mensagem = 'Julgamento recusado: '.$reason;

        $this->run->update([
            'status' => 'pending',
            'auto_judge_result' => $mensagem,
        ]);

        $contestId = $this->run->contest_id;

        // `contest_logs.contest_id` e obrigatorio -- uma run sem prova nao
        // gera registro ali, e a tela que le esses registros filtra por
        // prova de qualquer forma.
        if ($contestId !== null) {
            ContestLog::warning((int) $contestId, $mensagem, [
                'run_id' => $this->run->id,
                'host' => gethostname(),
            ]);
        }

        Log::warning($mensagem, ['run_id' => $this->run->id, 'host' => gethostname()]);
    }

    public function failed(\Throwable $exception): void
    {
        $this->run->update([
            'status' => 'judged',
            'auto_judge_result' => 'Job failed: ' . $exception->getMessage(),
            'auto_judge_end' => now(),
        ]);
    }
}
