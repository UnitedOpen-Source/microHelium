<?php

namespace App\Services\Clics;

use App\Models\Contest;
use App\Models\Run;
use App\Services\ContestClock;
use App\Services\FrozenScoreboard;

/**
 * Issue #319 -- este run caiu na janela de congelamento DA SEDE DELE?
 *
 * ## O defeito
 *
 * O #276 ligou duracao e congelamento proprios por sede, e `ContestClock`
 * passou a responder corretamente QUANDO cada sede congela. O corte que
 * decide O QUE esconder continuou global. O #341 consertou o placar --
 * `FrozenScoreboard::cell()` compara cada run com o corte da sede dele --
 * e deixou os tres chamadores CLICS no corte do contest:
 *
 *   ContestEventRecorder::isAfterFreeze()   grava `after_freeze` no log
 *   ContestApiController::judgements()      filtra a lista do REST
 *   ClicsPresenter::frozenAt()              publica `state.frozen`
 *
 * Numa prova de 300/60 com uma sede de janela mais curta (240/60), a sede
 * congela no minuto 180 dela e o corte global fica no 240: os sessenta
 * minutos entre um e outro -- a ULTIMA HORA INTEIRA daquela sede -- saiam
 * no event feed e no /judgements enquanto ela ainda competia.
 *
 * A direcao oposta e o mesmo defeito com o sinal trocado: uma sede de
 * janela mais longa (360/60) congela no minuto 300, e o corte global em 240
 * escondia sessenta minutos que ela nao precisava esconder.
 *
 * ## A restricao normativa
 *
 * Contest API 2023-06, secao "Judgements": a visibilidade dos julgamentos
 * durante o congelamento e o que separa o placar publico do placar da
 * banca, e a spec trata o congelamento como propriedade do PLACAR -- e o
 * placar, aqui, congela por sede desde o #276. O argumento inteiro esta
 * escrito no docblock de `ContestApiController::judgements()`: um consumidor
 * que recebesse o veredito de um envio do congelamento saberia, pelo
 * /judgements, o que o /scoreboard esconde.
 *
 * ## Uma definicao, tres chamadores
 *
 * Esta classe existe para que a pergunta nao tenha duas respostas. Ela e a
 * MESMA comparacao que `FrozenScoreboard::cell()` faz -- tempo ajustado da
 * sede contra o corte da sede -- e e por isso que o feed esconde exatamente
 * o que o placar esconde, e nem um run a mais.
 *
 * O tempo comparado e AJUSTADO (#198), e nao cru. `cutoffSeconds()` esta em
 * tempo QUE CONTA (`duration - freeze`), entao comparar `contest_time` cru
 * com ele mede duas grandezas diferentes: com um intervalo removido da
 * prova, o congelamento comecaria cedo demais por exatamente o tanto que
 * foi removido -- e o placar, que ja compara ajustado, discordaria do feed.
 */
class FreezeWindow
{
    /** @var array<int, array{0: int, 1: int}> siteId => [minutos de congelamento, corte em segundos] */
    private array $cache = [];

    public function __construct(private ContestClock $clock) {}

    /**
     * Verdadeiro quando o envio aconteceu depois do inicio do congelamento
     * da sede dele.
     *
     * Pergunta sobre o ENVIO e nao sobre o julgamento, que e a escolha que o
     * #219 ja tinha feito e continua valendo: um envio anterior ao corte e
     * julgado depois continua sendo informacao anterior ao congelamento.
     */
    public function covers(Contest $contest, Run $run): bool
    {
        $siteId = $run->site_id !== null ? (int) $run->site_id : null;

        [$freezeMinutes, $cutoff] = $this->windowFor($contest, $siteId);

        // Zero quer dizer "sem congelamento", por sede (#276). E preciso
        // perguntar a SEDE e nao ao contest: uma prova sem congelamento cuja
        // sede define trinta minutos congela naquela sede, e so nela -- e a
        // leitura antiga, que olhava `contests.freeze_time`, respondia
        // "nunca esconde" para aquela sede.
        if ($freezeMinutes <= 0) {
            return false;
        }

        return $this->clock->adjusted($contest, $siteId, (int) $run->contest_time) >= $cutoff;
    }

    /**
     * Memoizado por sede porque a pergunta e feita uma vez por run, e sao
     * milhares numa regional -- a mesma razao escrita em
     * `FrozenScoreboard::rows()`. A resposta so depende da sede.
     *
     * @return array{0: int, 1: int}
     */
    private function windowFor(Contest $contest, ?int $siteId): array
    {
        $chave = $siteId ?? 0;

        return $this->cache[$chave] ??= [
            $this->clock->freezeMinutesFor($contest, $siteId),
            FrozenScoreboard::cutoffSeconds($contest, $siteId),
        ];
    }
}
