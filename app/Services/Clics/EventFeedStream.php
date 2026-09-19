<?php

namespace App\Services\Clics;

use App\Models\Contest;

/**
 * Issue #328 -- o que faz o event feed ser um STREAM.
 *
 * ## O defeito que este arquivo existe para corrigir
 *
 * O feed emitia a fotografia, emitia o backlog, e a closure do
 * `response()->stream()` RETORNAVA. Retornar fecha a resposta. Medido com a
 * prova rodando, longe de terminar:
 *
 *     status=200 tempo_total=0,342207s bytes=4937
 *
 * O `--max-time 20` do curl nao foi atingido: ele recebeu EOF. Era um GET de
 * um arquivo com `Content-Type` de stream.
 *
 * ## O que a spec exige
 *
 * Contest API 2023-06, secao "Event feed":
 *
 *   "The event feed is a streaming HTTP endpoint that allows connected
 *    clients to receive change notifications. [...] The feed does not
 *    terminate under normal circumstances, so to ensure keep alive a newline
 *    must be sent if there has been no event within 120 seconds."
 *
 * Identico em 2026-01.
 *
 * ## Por que o EOF nao era um detalhe
 *
 * O cliente de referencia (`ContestSource` do ICPC Tools) trata EOF como
 * queda de conexao e reconecta com back-off de 2 s e depois 20 s. Numa
 * prova, isso e todo veredito chegando ao resolver e ao placar alternativo
 * com ate 20 s de atraso -- e, a cada 20 s, o feed inteiro baixado de novo.
 *
 * ## O laco
 *
 * Polling, e nao LISTEN/NOTIFY: o microHelium roda em sqlite, MySQL e
 * Postgres, e uma consulta por segundo por cliente conectado cabe no
 * orcamento de uma prova (dezenas de consumidores, nao milhares). Trocar por
 * notificacao do banco e uma mudanca DESTE arquivo, sem tocar no contrato.
 *
 * Custo operacional que precisa ficar dito: cada cliente conectado ocupa um
 * worker PHP-FPM durante a prova inteira. `pm.max_children` tem de caber os
 * consumidores esperados -- e e por isso que `set_time_limit(0)` esta aqui e
 * nao escondido numa configuracao.
 */
class EventFeedStream
{
    public function __construct(private EventFeedBuilder $feed) {}

    /**
     * Mantem a conexao aberta ate a prova acabar de verdade.
     *
     * `$emitir` recebe a linha a emitir, ou `null` para o newline de
     * keep-alive -- a spec pede um newline, e nao um evento vazio, porque um
     * evento vazio seria uma mudanca que nao aconteceu.
     *
     * Sai por tres portas, e so por elas:
     *
     * 1. `end_of_updates`, que so existe depois de finalizar (#202). E a
     *    unica saida que a spec chama de normal.
     * 2. o cliente desligou (`connection_aborted()`).
     * 3. o teto de `clics.event_feed.max_seconds`, que em producao e `null`
     *    (sem teto).
     *
     * @param  callable(array<string, mixed>|null): void  $emitir
     */
    public function run(Contest $contest, ?int $desde, bool $unrestricted, callable $emitir): void
    {
        // Sem isto o `max_execution_time` do php.ini mata a conexao no meio
        // da prova, e o sintoma no cliente e identico ao defeito que esta
        // classe corrige: EOF sem motivo.
        @set_time_limit(0);

        $intervalo = max(1, (int) config('clics.event_feed.poll_ms', 1000)) * 1000;
        $keepAlive = max(0.001, (float) config('clics.event_feed.keep_alive_seconds', 30));
        $teto = config('clics.event_feed.max_seconds');
        $teto = $teto === null ? null : (float) $teto;

        $liberado = $unrestricted || $contest->unfrozen_at !== null;

        if ($desde === null) {
            foreach ($this->feed->snapshot($contest) as $linha) {
                $emitir($linha);
            }
        }

        $inicio = microtime(true);
        $ultimoSinal = $inicio;
        $cursor = $desde;
        $retidos = [];

        while (true) {
            $lote = $this->feed->batch($contest, $cursor, $liberado);
            $cursor = $lote['cursor'];
            $retidos = array_merge($retidos, $lote['retidos']);

            foreach ($lote['linhas'] as $linha) {
                $emitir($linha);
                $ultimoSinal = microtime(true);
            }

            // Descongelou com o cliente CONECTADO.
            //
            // No REST e na reconexao isto se resolve sozinho -- a consulta
            // seguinte ja nao filtra. Aqui nao: o cursor ja passou por cima
            // dos eventos retidos, e sem esta liberacao o painel que ficou
            // conectado a prova inteira seria justamente o que nunca veria
            // os julgamentos do congelamento.
            if (! $liberado && $contest->unfrozen_at !== null) {
                $liberado = true;

                foreach ($this->feed->releases($contest, $retidos) as $linha) {
                    $emitir($linha);
                    $ultimoSinal = microtime(true);
                }

                $retidos = [];
            }

            if (($fim = $this->feed->endOfUpdates($contest)) !== null) {
                $emitir($fim);

                return;
            }

            if ($teto !== null && (microtime(true) - $inicio) >= $teto) {
                return;
            }

            // O cliente desligou. Sem esta saida, um resolver que fecha a
            // janela deixa um worker PHP-FPM preso ate o fim da prova.
            if (connection_aborted() !== 0) {
                return;
            }

            if ((microtime(true) - $ultimoSinal) >= $keepAlive) {
                $emitir(null);
                $ultimoSinal = microtime(true);
            }

            usleep($intervalo);

            // O estado da prova (descongelamento, finalizacao) muda por
            // FORA desta conexao. Sem o refresh, a instancia carregada no
            // inicio responderia para sempre que a prova nao acabou.
            $contest->refresh();
        }
    }
}
