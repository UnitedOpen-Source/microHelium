<?php

namespace App\Services\JudgingAlerts;

use App\Models\Contest;
use App\Models\Judgehost;
use App\Models\Run;

/**
 * Issue #199 -- reads the four conditions the issue lists, and nothing else.
 *
 * Every one of them is answered by data that already existed before this
 * file: `judgehosts.last_seen_at` (#53), `runs.status`, `give_back_reason`
 * and `give_back_count` (#125), `reconcile_attempts` (#45). Nothing new is
 * measured here -- what was missing was somebody looking.
 *
 * Deliberately has no opinion about delivery, hysteresis or cooldown: it
 * answers "what is wrong at this instant", and JudgingAlertDispatcher
 * decides whether that is worth waking anyone over. Keeping the two apart
 * is what makes it possible to test "the condition is detected" separately
 * from "the condition was announced", which are different failures.
 */
class JudgingAlertEvaluator
{
    /**
     * @return list<JudgingAlert>
     */
    public function evaluate(Contest $contest): array
    {
        return array_values(array_filter([
            $this->noJudgehost(),
            $this->queueBacklog($contest),
            $this->giveBackLoop($contest),
            $this->watchdogGaveUp($contest),
        ]));
    }

    /**
     * Nenhuma maquina habilitada deu noticia dentro do prazo.
     *
     * Only when at least one enabled judgehost exists. An installation that
     * judges through the local queue instead of the pull protocol has no
     * judgehost rows at all, and telling it that no judge machine answered
     * would be a permanent false alarm -- the fastest way to teach an
     * organisation to ignore this channel.
     *
     * A host that has never been seen (`last_seen_at` null) counts as
     * silent: registered and never having spoken is not better than having
     * stopped speaking.
     */
    private function noJudgehost(): ?JudgingAlert
    {
        $enabled = Judgehost::where('enabled', true)->count();

        if ($enabled === 0) {
            return null;
        }

        $silenceSeconds = (int) config('judging.alerts.judgehost_silence_seconds', 120);
        $cutoff = now()->subSeconds($silenceSeconds);

        $alive = Judgehost::where('enabled', true)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $cutoff)
            ->count();

        if ($alive > 0) {
            return null;
        }

        return new JudgingAlert(
            condition: 'no_judgehost',
            key: 'no_judgehost',
            title: 'Nenhuma maquina de julgamento respondeu',
            detail: "Todas as {$enabled} maquina(s) habilitada(s) estao sem dar noticia ha mais de {$silenceSeconds}s. Nada esta sendo julgado.",
            dwellMinutes: (int) config('judging.alerts.dwell_minutes.no_judgehost', 2),
            context: ['enabled_judgehosts' => $enabled, 'silence_seconds' => $silenceSeconds],
        );
    }

    /**
     * Fila pendente acima do limite.
     *
     * The key carries no count, on purpose: a backlog that grows from 21 to
     * 47 is the same backlog, and keying on the number would restart the
     * dwell -- and re-announce -- on every single tick it changed.
     */
    private function queueBacklog(Contest $contest): ?JudgingAlert
    {
        $threshold = (int) config('judging.alerts.queue_depth', 20);
        $pending = Run::where('contest_id', $contest->id)->where('status', 'pending')->count();

        if ($pending <= $threshold) {
            return null;
        }

        return new JudgingAlert(
            condition: 'queue_backlog',
            key: 'queue_backlog',
            title: 'Fila de julgamento represada',
            detail: "{$pending} envio(s) aguardando julgamento, acima do limite de {$threshold}.",
            dwellMinutes: $this->defaultDwell(),
            context: ['pending' => $pending, 'threshold' => $threshold],
        );
    }

    /**
     * O mesmo run devolvido varias vezes pelo mesmo motivo (#125).
     *
     * Not bad luck after the third time: it is one failure repeating, and
     * no further retry is going to end differently. The reason travels in
     * the key so that a compile-timeout loop and an out-of-disk loop are
     * two alerts and not one that keeps mutating.
     */
    private function giveBackLoop(Contest $contest): ?JudgingAlert
    {
        $repeats = (int) config('judging.alerts.give_back_repeats', 3);

        $run = Run::where('contest_id', $contest->id)
            ->whereNotNull('give_back_reason')
            ->where('give_back_count', '>=', $repeats)
            ->orderByDesc('give_back_count')
            ->first();

        if (! $run) {
            return null;
        }

        return new JudgingAlert(
            condition: 'give_back_loop',
            key: 'give_back_loop:'.$run->give_back_reason,
            title: 'Envio devolvido repetidamente pelo mesmo motivo',
            detail: "Run #{$run->run_number} foi devolvido {$run->give_back_count} vezes por \"{$run->give_back_reason}\". Retentativa nao vai resolver.",
            // Evento, nao estado: a coluna que registra a devolucao nunca
            // volta atras, entao a condicao ficaria "valendo" ate o fim da
            // prova e se repetiria a cada cooldown. A chave leva o motivo,
            // entao quarenta runs derrubados pela mesma falha sao um aviso
            // -- que e exatamente o que se quer saber -- e uma falha
            // diferente e outro.
            dwellMinutes: 0,
            repeatable: false,
            context: [
                'run_id' => $run->id,
                'run_number' => $run->run_number,
                'give_back_reason' => $run->give_back_reason,
                'give_back_count' => (int) $run->give_back_count,
            ],
        );
    }

    /**
     * O watchdog desistiu de um run.
     *
     * ReconcileStuckRunsCommand closes such a run as a judge error and
     * writes it to the contest log, which is honest and entirely passive:
     * a team's submission was thrown away by our own infrastructure and the
     * only trace is a line somebody has to go and read.
     *
     * Counted over the contest, so the alert says how many rather than
     * naming one -- if the watchdog is giving up at all, the number is the
     * information.
     */
    private function watchdogGaveUp(Contest $contest): ?JudgingAlert
    {
        $given = Run::where('contest_id', $contest->id)
            ->where('reconcile_attempts', '>=', 1)
            ->where('status', 'judged')
            ->whereHas('answer', fn ($q) => $q->where('short_name', 'CS'))
            ->count();

        if ($given === 0) {
            return null;
        }

        return new JudgingAlert(
            condition: 'watchdog_gave_up',
            // A contagem entra na chave, e por isso o dwell e zero.
            //
            // Desistir ja aconteceu -- a run foi encerrada, o veredito foi
            // gravado, nada disso volta atras -- entao esperar cinco
            // minutos para contar seria so atraso, e uma chave fixa faria o
            // aviso se repetir ate o fim da prova. Com a contagem na chave,
            // cada desistencia nova rende exatamente um aviso, e cada aviso
            // corresponde a uma submissao de equipe que a infraestrutura
            // jogou fora. Se forem cinquenta, cinquenta e o numero que a
            // organizacao precisa ver.
            key: 'watchdog_gave_up:'.$given,
            title: 'A recuperacao automatica desistiu de envios',
            detail: "{$given} envio(s) foram encerrados como erro de julgamento apos o watchdog desistir. Sao submissoes de equipe que a infraestrutura descartou.",
            dwellMinutes: 0,
            repeatable: false,
            context: ['given_up' => $given],
        );
    }

    private function defaultDwell(): int
    {
        return (int) config('judging.alerts.dwell_minutes.default', 5);
    }
}
