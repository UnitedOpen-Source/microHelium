<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Alertas operacionais
    |--------------------------------------------------------------------------
    |
    | Issue #199 -- quando o julgamento para, alguem precisa ser avisado.
    |
    | O #45 pos um watchdog que recupera run travado e o #53 pos lease,
    | heartbeat e devolucao com motivo. Tudo isso funciona e nao conta a
    | ninguem: a organizacao descobre que nada esta sendo julgado porque
    | alguem, meia hora depois, foi olhar uma tela. Os dados para evitar
    | isso ja existem todos; o que faltava era o empurrao.
    |
    | O canal e um webhook de proposito. Telegram numa prova brasileira e
    | uma escolha razoavel -- e onde a organizacao ja esta, e e o que o MOJ
    | faz -- mas amarrar a escolha aqui obrigaria quem usa Slack, ou um
    | bipe na sala, a mexer no codigo. Do outro lado do webhook cada um liga
    | o que quiser.
    |
    */
    'alerts' => [

        // Desligar nao e o mesmo que nao configurar webhook: sem webhook os
        // alertas continuam indo para o log de contest (#88), que e onde a
        // banca procura depois. Isto aqui cala as duas coisas.
        'enabled' => (bool) env('JUDGING_ALERTS_ENABLED', true),

        /*
        | Histerese: quanto tempo a condicao precisa se sustentar antes de
        | virar alerta.
        |
        | Sem isso, reiniciar um judgehost no intervalo do almoco dispara
        | "nenhuma maquina respondeu", e um alerta que mente e um alerta que
        | a organizacao aprende a ignorar -- que e o mesmo que nao ter.
        |
        | `no_judgehost` tem folga menor porque a propria janela de silencio
        | abaixo ja e uma espera: as duas se somam.
        */
        'dwell_minutes' => [
            'default' => (int) env('JUDGING_ALERTS_DWELL_MINUTES', 5),
            'no_judgehost' => (int) env('JUDGING_ALERTS_DWELL_NO_JUDGEHOST', 2),
        ],

        /*
        | Cooldown. Um alerta que repete a cada cinco minutos durante uma
        | prova e um alerta que alguem silencia, e a partir dai ele nao
        | serve para nada -- inclusive para o problema seguinte.
        |
        | Enquanto a condicao continua valendo, o aviso se repete no maximo
        | uma vez por este intervalo. 0 significa "avise uma vez e fique
        | quieto ate a condicao passar".
        */
        'repeat_after_minutes' => (int) env('JUDGING_ALERTS_REPEAT_MINUTES', 60),

        /*
        | Quanto tempo sem noticia de uma maquina habilitada ja e silencio.
        |
        | O agente (#116) faz heartbeat a cada poucos segundos, entao dois
        | minutos e varias batidas perdidas e nao uma.
        */
        'judgehost_silence_seconds' => (int) env('JUDGING_ALERTS_JUDGEHOST_SILENCE_SECONDS', 120),

        // Fila pendente acima disto, sustentada pelo dwell, e represamento.
        'queue_depth' => (int) env('JUDGING_ALERTS_QUEUE_DEPTH', 20),

        // Um run devolvido tantas vezes pelo mesmo motivo (#125) nao e azar:
        // e a mesma falha acontecendo de novo, e nenhuma retentativa vai
        // resolver.
        'give_back_repeats' => (int) env('JUDGING_ALERTS_GIVE_BACK_REPEATS', 3),

        'webhook' => [
            'url' => (string) env('JUDGING_ALERTS_WEBHOOK_URL', ''),

            // Opcional. Quando presente, cada entrega leva um cabecalho
            // X-Microhelium-Signature com o HMAC-SHA256 do corpo, para que
            // o outro lado saiba que o aviso veio daqui -- um endpoint que
            // aceita qualquer POST e um endpoint que qualquer um usa para
            // dizer que o julgamento parou.
            'secret' => (string) env('JUDGING_ALERTS_WEBHOOK_SECRET', ''),

            // Curto de proposito: isto roda dentro do agendador, e um
            // receptor lento nao pode segurar a proxima verificacao.
            'timeout' => (int) env('JUDGING_ALERTS_WEBHOOK_TIMEOUT', 5),
        ],
    ],

];
