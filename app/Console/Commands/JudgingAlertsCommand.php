<?php

namespace App\Console\Commands;

use App\Models\Contest;
use App\Models\Run;
use App\Services\JudgingAlerts\JudgingAlertDispatcher;
use App\Services\JudgingAlerts\JudgingAlertEvaluator;
use Illuminate\Console\Command;

/**
 * Issue #199 -- o empurrao que faltava.
 *
 * O #45 pos um watchdog que recupera run travado, o #53 pos lease,
 * heartbeat e devolucao com motivo, o #191 pos a tela que mostra tudo
 * isso. Nada disso conta a ninguem. O unico judgehost cai as 15h de uma
 * prova de cinco horas, a fila cresce, as equipes veem "em avaliacao" e
 * acham normal, a banca esta resolvendo clarificacao, e meia hora depois
 * alguem pergunta por que nada foi julgado.
 *
 * A tela do #191 responde "o que esta acontecendo?"; isto responde "olhe
 * agora". Uma nao substitui a outra, e as duas leem os mesmos dados.
 */
class JudgingAlertsCommand extends Command
{
    protected $signature = 'judging:alerts';

    protected $description = 'Avalia as condicoes de saude do julgamento e avisa quando alguma se sustenta';

    public function handle(JudgingAlertEvaluator $evaluator, JudgingAlertDispatcher $dispatcher): int
    {
        if (! config('judging.alerts.enabled', true)) {
            $this->info('Alertas de julgamento desligados (judging.alerts.enabled).');

            return self::SUCCESS;
        }

        $contests = Contest::query()->competition()->where('is_active', true)->get();
        $announced = 0;

        foreach ($contests as $contest) {
            if (! $this->isLive($contest)) {
                continue;
            }

            foreach ($dispatcher->dispatch($contest, $evaluator->evaluate($contest)) as $entry) {
                $announced++;
                $key = is_string($entry['alert']) ? $entry['alert'] : $entry['alert']->key;
                $this->warn("[{$contest->name}] {$entry['event']}: {$key}");
            }
        }

        $this->info("{$announced} aviso(s) emitido(s).");

        return self::SUCCESS;
    }

    /**
     * Um evento acontecendo agora, ou ainda com trabalho parado dentro.
     *
     * Sem esta porta, um contest do ano passado que alguem esqueceu com
     * is_active = true dispararia "nenhuma maquina de julgamento respondeu"
     * todo dia, para sempre -- e um canal que avisa todo dia e um canal que
     * ninguem le no dia em que importa. A fila pendente entra junto porque
     * um envio feito no ultimo minuto ainda precisa ser julgado depois que
     * o relogio zera.
     */
    private function isLive(Contest $contest): bool
    {
        if ($contest->isRunning()) {
            return true;
        }

        return Run::where('contest_id', $contest->id)
            ->whereIn('status', ['pending', 'judging'])
            ->exists();
    }
}
