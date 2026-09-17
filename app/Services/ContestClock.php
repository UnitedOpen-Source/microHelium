<?php

namespace App\Services;

use App\Models\Contest;
use App\Models\ContestTimeAdjustment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Issue #198 -- o relogio da prova depois de tirar os pedacos que nao contam.
 *
 * O desenho em uma frase: `runs.contest_time` continua sendo o tempo CRU de
 * parede desde o inicio, e o ajuste acontece na LEITURA.
 *
 * Isso nao e detalhe de implementacao, e o que torna a remocao reversivel,
 * que a spec exige em voz alta ("The removal of a time interval must be
 * reversible"). Reescrever o contest_time gravado seria destrutivo: desfazer
 * exigiria lembrar o valor antigo de cada run, e um segundo intervalo
 * removido antes do primeiro mudaria a conversao de todos eles. Com o ajuste
 * na leitura, adicionar ou remover um intervalo e so recomputar -- e
 * Score::recomputeFor() ja e uma funcao pura dos runs que contam (#171),
 * entao a recomposicao e a mesma que qualquer rejulgamento faz.
 *
 * A ORDEM vem do tempo cru, e o VALOR vem do ajustado. E o que satisfaz
 * exatamente a terceira exigencia: "If submission S_i arrived before
 * submission S_j during a removed interval, S_i must still be considered by
 * the CCS to have arrived strictly before S_j." Dois envios dentro do
 * intervalo removido colapsam para o mesmo instante ajustado -- se a ordem
 * viesse dali, eles empatariam. Vindo do cru, nao empatam.
 */
class ContestClock
{
    /**
     * Memoizado por instancia: uma tela de placar pergunta isto uma vez por
     * celula, e sao centenas numa regional.
     *
     * @var array<int, Collection<int, ContestTimeAdjustment>>
     */
    private array $cache = [];

    /**
     * Quantos segundos deste contest NAO contam para esta sede, ate o
     * instante cru informado.
     *
     * Contado em tempo de parede desde o inicio da prova, que e a unidade de
     * `runs.contest_time`.
     */
    public function removedSecondsBefore(Contest $contest, ?int $siteId, int $rawSeconds): int
    {
        if (! $contest->start_time) {
            return 0;
        }

        $moment = $contest->start_time->copy()->addSeconds($rawSeconds);
        $removed = 0;

        foreach ($this->adjustments($contest) as $adjustment) {
            if (! $adjustment->appliesToSite($siteId)) {
                continue;
            }

            // Um envio DENTRO do intervalo removido colapsa para o comeco
            // dele: o tempo que nao conta e so o que ja passou. Sem este
            // `min`, um envio feito no meio da queda de energia receberia o
            // desconto inteiro e apareceria ANTES do comeco da queda.
            $end = $adjustment->ends_at->lt($moment) ? $adjustment->ends_at : $moment;

            // Sem um `if (starts_at >= moment) continue;` antes desta linha.
            //
            // Ele existia, e uma mutacao mostrou que remove-lo nao derrubava
            // teste nenhum -- porque era redundante: para um intervalo que
            // ainda nao comecou, `$end` vira o proprio instante consultado,
            // a diferenca sai negativa, e o `max(0, ...)` ja devolve zero.
            // Uma linha que parece proteger e nao protege e pior do que a
            // sua ausencia, porque a proxima pessoa confia nela. Terceira
            // vez nesta sessao que uma mutacao encontra isso.
            $removed += max(0, (int) $adjustment->starts_at->diffInSeconds($end));
        }

        return $removed;
    }

    /**
     * O tempo de prova que este envio vale, depois dos descontos.
     */
    public function adjusted(Contest $contest, ?int $siteId, int $rawSeconds): int
    {
        return max(0, $rawSeconds - $this->removedSecondsBefore($contest, $siteId, $rawSeconds));
    }

    /**
     * Quantos segundos esta sede ganha no fim da prova.
     *
     * A prova acaba quando a sede tiver vivido `duration` de tempo QUE
     * CONTA, entao um intervalo removido empurra o fim daquela sede para
     * frente. E por isso que a extensao por sede e o mesmo mecanismo do
     * intervalo removido, e nao uma segunda coisa.
     */
    public function extensionSeconds(Contest $contest, ?int $siteId): int
    {
        $total = 0;

        foreach ($this->adjustments($contest) as $adjustment) {
            if ($adjustment->appliesToSite($siteId)) {
                $total += $adjustment->seconds();
            }
        }

        return $total;
    }

    /**
     * O fim da prova PARA ESTA SEDE.
     *
     * Issue #287 -- calculado a partir de `start_time`, e nao de
     * `$contest->end_time`.
     *
     * Partir do `end_time` somava a extensao global DUAS VEZES:
     * `Contest::getEndTimeAttribute()` ja soma `extensionSeconds(..., null)`,
     * e `extensionSeconds(..., $siteId)` inclui os globais de novo, porque
     * `ContestTimeAdjustment::appliesToSite()` diz -- corretamente -- que um
     * ajuste global vale para toda sede.
     *
     * Medido antes do conserto, prova de 300 min com um ajuste global de 30:
     *
     *   contest->end_time  +30min   correto
     *   endTimeFor(null)   +60min
     *   endTimeFor(site)   +60min
     *
     * E nao era erro de tela: `isRunningFor()` sai daqui, e os dois caminhos
     * de envio decidem por ele. Uma queda de energia nacional de 40 minutos
     * registrada como ajuste global -- o uso mais obvio do mecanismo -- dava
     * 80 minutos extras de submissao a TODAS as sedes, inclusive as que nao
     * tinham ajuste proprio nenhum.
     */
    public function endTimeFor(Contest $contest, ?int $siteId): ?Carbon
    {
        if (! $contest->start_time) {
            return null;
        }

        return Carbon::instance(
            $contest->start_time->copy()
                ->addMinutes((int) $contest->duration)
                ->addSeconds($this->extensionSeconds($contest, $siteId))
        );
    }

    /**
     * A prova ainda esta correndo PARA ESTA SEDE?
     *
     * Uma sede que perdeu quarenta minutos ainda submete quarenta minutos
     * depois de as outras terem acabado -- e e esse o ponto inteiro.
     */
    public function isRunningFor(Contest $contest, ?int $siteId): bool
    {
        if (! $contest->is_active || ! $contest->start_time) {
            return false;
        }

        $now = now();
        $end = $this->endTimeFor($contest, $siteId);

        return $now->gte($contest->start_time) && $end !== null && $now->lte($end);
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /**
     * @return Collection<int, ContestTimeAdjustment>
     */
    private function adjustments(Contest $contest): Collection
    {
        return $this->cache[$contest->id] ??= ContestTimeAdjustment::where('contest_id', $contest->id)
            ->orderBy('starts_at')
            ->get();
    }
}
