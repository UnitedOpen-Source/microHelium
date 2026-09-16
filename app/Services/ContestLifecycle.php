<?php

namespace App\Services;

use App\Models\Contest;
use App\Models\ContestLog;
use Helium\User;

/**
 * Issue #225 -- congelar agora e encerrar mais cedo, com semantica que
 * bate com o modelo.
 *
 * Os dois atalhos legados de routes/web.php faziam, medido ponta a ponta, o
 * OPOSTO do que o botao prometia:
 *
 *   POST /backend/contest/freeze  gravava `freeze_time = 0`, e o #189 le
 *                                 zero como "sem congelamento nenhum". O
 *                                 botao escrito "Placar congelado!"
 *                                 DESCONGELAVA o placar.
 *
 *   POST /backend/contest/end     gravava `is_active = false`, e isFrozen()
 *                                 devolvia falso para contest inativo. O
 *                                 botao de encerrar PUBLICAVA a
 *                                 classificacao.
 *
 * Um servico e nao duas closures na rota porque a issue pede as mesmas
 * regras nos caminhos web e API, e duas copias de uma regra sobre publicar
 * classificacao e como uma delas fica para tras.
 */
class ContestLifecycle
{
    public function __construct(private ContestClock $clock) {}

    /**
     * Congela agora, ate alguem revelar.
     *
     * `freeze_time` e "minutos ANTES DO FIM", entao congelar neste instante
     * e uma janela do tamanho do que falta. Nunca zero: zero significa "sem
     * congelamento" desde o #189, e era o que tornava este atalho um botao
     * de publicar.
     *
     * `max(1, ...)` cobre a prova que ja acabou: qualquer janela positiva
     * mantem o placar congelado depois do fim, porque o #189 fez o
     * congelamento sobreviver ao termino -- que e a cerimonia inteira.
     */
    public function freezeNow(Contest $contest, ?User $actor): void
    {
        $remaining = 0;

        if ($contest->end_time !== null) {
            $remaining = (int) ceil(now()->diffInSeconds($contest->end_time) / 60);
        }

        $minutes = max(1, $remaining);

        $contest->update(['freeze_time' => $minutes, 'unfrozen_at' => null]);

        ContestLog::warning($contest->id, "Placar congelado a partir de agora (janela de {$minutes} min)", [
            'event' => 'contest_frozen_now',
            'freeze_time' => $minutes,
            'user_id' => $actor?->user_id,
        ]);
    }

    /**
     * Encerra a prova agora, mantendo o resultado congelado.
     *
     * Encurta a DURACAO em vez de desativar o contest, e a diferenca e o
     * ponto inteiro da issue: desativar era confundido com terminar, e
     * desativar publicava a classificacao.
     *
     * Encurtar a duracao flui por todo consumidor do relogio que ja existe
     * -- isRunning(), end_time, o corte do congelamento, a extensao por sede
     * do #198 -- sem uma coluna nova e sem um segundo conceito de "quando
     * acabou".
     *
     * O contest continua ATIVO: ele e o evento corrente ate alguem trocar.
     * Terminar e revelar sao decisoes separadas, e e por isso que existem
     * dois botoes.
     */
    public function endEarly(Contest $contest, ?User $actor): void
    {
        if (! $contest->start_time) {
            return;
        }

        // Descontando os intervalos removidos da prova inteira (#198): a
        // duracao e medida em tempo QUE CONTA, e somar o tempo descontado
        // faria a prova "acabar" depois do instante em que se pediu que ela
        // acabasse.
        $extension = $this->clock->extensionSeconds($contest, null);
        $elapsed = max(0, (int) $contest->start_time->diffInSeconds(now()) - $extension);

        $contest->update(['duration' => max(1, (int) ceil($elapsed / 60))]);

        ContestLog::warning($contest->id, 'Competicao encerrada antes do horario previsto', [
            'event' => 'contest_ended_early',
            'duration' => $contest->fresh()->duration,
            'user_id' => $actor?->user_id,
        ]);
    }
}
