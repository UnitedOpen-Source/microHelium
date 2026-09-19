<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Issue #329 -- o backstop de TEMPO DE PAREDE do juiz disparou.
 *
 * Os dois limites do `AutoJudgeService` que sao medidos no relogio -- o da
 * compilacao e o da execucao -- existem para que um passo travado nao
 * segure a maquina para sempre. Eles sao diferentes do `ulimit -t` que o
 * sandbox aplica: aquele conta CPU e produz `TLE`, que e um veredito sobre
 * o programa da equipe; estes contam parede e terminam em `CS`, que e um
 * veredito sobre nos.
 *
 * Ate esta excecao existir, os dois casos chegavam ao mesmo lugar com a
 * mesma cara: o que sobrava em `auto_judge_stderr` era a linha de comando
 * inteira do `bwrap`, com todos os `--ro-bind`, dentro da mensagem de
 * excecao do Symfony. Quem investigava nao descobria dali que o que houve
 * foi um estouro de relogio -- e essa e a informacao que separa "a maquina
 * estava ocupada" de "o sandbox nao subiu".
 *
 * A excecao carrega a etapa e o limite para que a mensagem diga as duas
 * coisas que importam: quanto tempo foi dado, e em que passo acabou.
 */
class JudgeWallClockTimeoutException extends RuntimeException
{
    public function __construct(
        public readonly string $etapa,
        public readonly int $wallSeconds,
    ) {
        parent::__construct(
            "O julgamento excedeu {$wallSeconds} s de tempo de parede na etapa de {$etapa}. "
            .'Este e um limite de relogio da infraestrutura do juiz, e nao o limite de CPU do problema: '
            .'o `ulimit -t` do sandbox nao disparou, entao isto nao e um veredito sobre o programa enviado. '
            .'Sob carga, a causa costuma ser a maquina de julgamento (issue #329).'
        );
    }
}
