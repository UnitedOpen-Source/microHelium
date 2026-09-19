<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Event feed (issue #328)
    |--------------------------------------------------------------------------
    |
    | Contest API 2023-06, secao "Event feed":
    |
    |   "The event feed is a streaming HTTP endpoint [...] The feed does not
    |    terminate under normal circumstances, so to ensure keep alive a
    |    newline must be sent if there has been no event within 120 seconds."
    |
    | Os tres numeros abaixo sao o que faz isso ser verdade aqui.
    |
    */

    'event_feed' => [

        /*
         * De quanto em quanto tempo a conexao aberta olha o log.
         *
         * Um segundo e o compromisso entre "o veredito aparece no painel no
         * mesmo segundo" e "nao transformar cada cliente conectado num laco
         * de consultas". Quem quiser trocar polling por LISTEN/NOTIFY mexe
         * em EventFeedStream, nao aqui.
         */
        'poll_ms' => (int) env('CLICS_EVENT_FEED_POLL_MS', 1000),

        /*
         * O newline de keep-alive.
         *
         * A spec da o TETO (120 s), e nao o intervalo. 30 s deixa margem
         * para um proxy com timeout de 60 s no meio do caminho -- que e o
         * que derruba o cliente sem ninguem perceber. Manter abaixo de 120.
         */
        'keep_alive_seconds' => (float) env('CLICS_EVENT_FEED_KEEP_ALIVE_SECONDS', 30),

        /*
         * Teto de duracao de UMA conexao, em segundos.
         *
         * `null` e o valor da spec: o feed nao termina. E o default de
         * producao, e mexer nele e dizer ao cliente CLICS para reconectar
         * com back-off (o ContestSource do ICPC Tools espera 20 s da segunda
         * reconexao em diante -- ver #328).
         *
         * `0` significa "nao entre no laco": emite a fotografia, o backlog,
         * e volta. E o que a suite usa (phpunit.xml) para que os testes que
         * medem CONTEUDO nao fiquem presos numa conexao que, por definicao,
         * nao fecha. Os testes que medem o STREAM ligam um teto positivo em
         * EventFeedStreamingContractTest.
         */
        'max_seconds' => env('CLICS_EVENT_FEED_MAX_SECONDS') === null
            ? null
            : (float) env('CLICS_EVENT_FEED_MAX_SECONDS'),
    ],

];
