<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lease
    |--------------------------------------------------------------------------
    |
    | Issue #53 -- how long a judge machine may hold a run before the server
    | takes it back.
    |
    | DOMjudge has no equivalent: its give-back runs when a judgehost
    | re-registers, which covers a host that comes back and not one that
    | dies. DOMjudge#2476 is what the gap looks like in practice -- a World
    | Finals judging that stayed assigned to a crashed host and needed a
    | manual rejudge.
    |
    | The value has to exceed the longest a legitimate judging can take: a
    | problem's time limit times its test-case count, plus compilation. Ten
    | minutes is comfortable for the contests this is sized for; raise it
    | before lowering it, because expiring a live judging hands the same run
    | to a second machine.
    |
    */
    'lease_seconds' => (int) env('JUDGEHOST_LEASE_SECONDS', 600),

    /*
    |--------------------------------------------------------------------------
    | Agent
    |--------------------------------------------------------------------------
    |
    | Issue #116 -- the side of #53 that runs on the judge machine.
    |
    | `server` and `token` are the only two things a partner institution has
    | to configure, and deliberately the only two: the agent speaks HTTP to
    | the server and nothing else. It needs no database credentials -- #119
    | measured that judging a run issues no queries at all -- which is what
    | makes it safe to hand a machine to someone else's rack.
    |
    | The token comes from the environment rather than the command line, so
    | it does not sit in `ps` output for every user on the box.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Calibracao
    |--------------------------------------------------------------------------
    |
    | Issue #196 -- a partir de quantas vezes a diferenca entre a maquina
    | mais lenta e a mais rapida deixa de ser ruido e vira aviso.
    |
    | 1.5 porque abaixo disso a variacao se explica por carga momentanea e
    | por diferencas de compilador, e acima disso o mesmo limite de tempo
    | comeca a significar coisas diferentes em maquinas diferentes -- que e
    | quando a equipe passa a receber TLE ou AC por sorteio de fila.
    |
    | Isto NAO muda veredito nenhum: e o limiar do aviso, e o #130 decidiu
    | que divergencia e avisada e nao compensada.
    |
    */
    'calibration' => [
        'divergence_threshold' => (float) env('JUDGEHOST_DIVERGENCE_THRESHOLD', 1.5),

        /*
         * Issue #251 (#196, fase 2) -- a FAIXA do limite servido por maquina.
         *
         * O limite efetivo e `digitado * fator`, preso entre `digitado *
         * piso` e `digitado * teto`. A forma foi decidida assim, e nao como
         * substituicao do valor digitado, para preservar o #117/#130 em
         * espirito: a divergencia passa a ser compensada, mas LIMITADA --
         * nenhuma maquina fica livre para inventar o proprio limite.
         *
         * Metade e o dobro sao escolhas de partida, e nao medicao: cobrem o
         * parque heterogeneo tipico (uma maquina duas vezes mais lenta que a
         * outra) e recusam o absurdo. Quem tiver medicao propria que
         * justifique outra faixa ajusta por env.
         *
         * Piso 1.0 e teto 1.0 juntos desligam o ajuste e devolvem o
         * comportamento da fase 1: medir e avisar, sem agir.
         */
        'limit_floor' => (float) env('JUDGEHOST_LIMIT_FLOOR', 0.5),
        'limit_ceiling' => (float) env('JUDGEHOST_LIMIT_CEILING', 2.0),
    ],

    'agent' => [
        'server' => rtrim((string) env('JUDGEHOST_SERVER', ''), '/'),
        'token' => env('JUDGEHOST_TOKEN', ''),

        // 204 from fetch-work is the server saying "nothing to do", not an
        // error, and the agent's answer is to ask less often. Without a
        // backoff, fifty idle hosts are fifty requests a second against the
        // server for the whole quiet stretch before a contest starts.
        'poll_min_seconds' => (float) env('JUDGEHOST_POLL_MIN_SECONDS', 1),
        'poll_max_seconds' => (float) env('JUDGEHOST_POLL_MAX_SECONDS', 30),

        // Where fetched work lands. Test cases and package scripts are kept
        // by digest, so a host judging 200 submissions of one problem
        // downloads its test data once.
        'workspace' => env('JUDGEHOST_WORKSPACE', 'judgehost'),

        /*
        |----------------------------------------------------------------------
        | Retention of fetched test data
        |----------------------------------------------------------------------
        |
        | Issue #186 -- how long a judge machine may keep the test data it
        | downloaded, counted from the last time a judging actually used it.
        |
        | The cache is what makes a warm judgehost worth having, and it is
        | also a pile of other people's hidden input and output sitting on a
        | machine in someone else's rack -- which is the premise of #53, not
        | an edge case. Nothing used to remove it: no command, no schedule,
        | no bound. A partner institution kept every problem of every
        | contest it had ever judged, for ever, and nobody had decided that.
        |
        | Seven days is chosen to outlive an event comfortably -- a contest
        | plus its practice sessions and the days either side when people
        | are still rejudging -- while making "for ever" stop being the
        | default. Raise it on a dedicated host that judges the same problem
        | set all season; lower it on a machine you borrowed.
        |
        | `judgehost:prune` is what enforces it, and it must run ON THE JUDGE
        | MACHINE: that is where the files are. The agent's own scheduler is
        | the natural place (routes/console.php runs it daily).
        |
        */
        'cache_retention_days' => (int) env('JUDGEHOST_CACHE_RETENTION_DAYS', 7),

        'request_timeout' => (int) env('JUDGEHOST_REQUEST_TIMEOUT', 30),
    ],

];
