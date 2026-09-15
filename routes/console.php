<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Issue #45: the "scheduler" service in docker-compose.yml already runs
// `php artisan schedule:run` in a loop, but nothing was actually scheduled
// on it until now.
// withoutOverlapping(): a large stuck-run backlog (or a hung JudgeRunJob
// dispatch on a sync queue connection) could make one run take longer than
// 5 minutes; without this, two concurrent instances could both increment
// reconcile_attempts or both give up on the same run.
Schedule::command('runs:reconcile-stuck')->everyFiveMinutes()->withoutOverlapping();

// Issue #159: tokens carry an expires_at (config/sanctum.php) and the guard
// refuses an expired one, so this changes no authorization decision -- it
// only stops personal_access_tokens growing without bound over successive
// contests on the same installation. --hours=24 keeps a just-expired row
// around for a day so that "my token stopped working" can still be answered
// by looking.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Issue #186 -- roda na MAQUINA DE JULGAMENTO, nao no servidor: e la que os
// arquivos estao. O cache de casos de teste e o que faz um judgehost quente
// valer a pena, e tambem e entrada e saida escondidas de outras pessoas num
// rack de outra instituicao (a premissa do #53). Nada o removia; agora ele
// e limitado por tempo desde o ultimo uso, e o padrao deixa de ser "para
// sempre".
//
// Inofensivo no servidor, onde o diretorio simplesmente nao existe.
Schedule::command('judgehost:prune')->daily();

// Issue #199 -- o watchdog do #45 recupera em silencio e a fila cresce sem
// alerta. Este comando so avalia e avisa; nao mexe em nenhum run, e por
// isso pode rodar com mais frequencia do que o reconciliador.
//
// A cada minuto porque a histerese e o cooldown ja moram dentro do
// dispatcher: a frequencia aqui e a resolucao com que a condicao e
// percebida, nao a frequencia com que alguem e incomodado. Com
// withoutOverlapping por causa do webhook -- um receptor lento nao pode
// fazer duas avaliacoes correrem juntas e duplicar o aviso.
Schedule::command('judging:alerts')->everyMinute()->withoutOverlapping();
