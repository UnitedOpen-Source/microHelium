<?php

namespace App\Services;

use App\Models\Clarification;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Run;
use App\Services\Clics\ContestEventRecorder;
use Helium\User;

/**
 * Issue #202 -- finalizar e uma checagem de integridade da prova inteira, e
 * nao um botao de publicar.
 *
 * Os requisitos de CCS nomeiam o que impede:
 *
 *   "Finalizing must not be possible if: The contest is still running...;
 *    There are un-judged submissions; There are submissions judged as
 *    Judging Error; There are unanswered clarification requests."
 *
 * Cada uma dessas e uma coisa que ainda poderia mudar a classificacao. E o
 * momento em que a organizacao afirma que nao sobrou nenhuma.
 *
 * Os impedimentos sao devolvidos como LISTA, um a um, e nao como um
 * booleano. A issue pede isso explicitamente, e a razao e concreta: um
 * botao que nao funciona sem dizer por que e um botao que alguem vai clicar
 * dez vezes antes de ir procurar no log.
 */
class ContestFinalizer
{
    /**
     * O que impede finalizar este contest agora.
     *
     * @return list<array{code: string, message: string, count?: int}>
     */
    public function blockers(Contest $contest): array
    {
        $blockers = [];

        // Issue #225 -- uma prova que NAO COMECOU passava no preflight sem
        // nenhum impedimento.
        //
        // `isRunning()` e falso para ela, entao a condicao "ainda esta
        // correndo" nao disparava; e sem envio, sem erro de julgamento e sem
        // clarificacao pendente -- que e o estado natural de uma prova que
        // nao aconteceu -- a lista saia vazia. Medido: `[]`.
        //
        // Finalizar e a organizacao AFIRMANDO que nao sobrou nada pendente
        // que pudesse mudar a classificacao. De uma prova que nao comecou
        // sobra tudo.
        if (! $contest->start_time) {
            $blockers[] = [
                'code' => 'contest_not_scheduled',
                'message' => 'Esta prova nao tem horario de inicio.',
            ];
        } elseif (now()->lt($contest->start_time)) {
            $blockers[] = [
                'code' => 'contest_not_started',
                'message' => 'Esta prova ainda nao comecou.',
            ];
        }

        if ($contest->isRunning()) {
            $blockers[] = [
                'code' => 'contest_running',
                'message' => 'A prova ainda esta correndo.',
            ];
        }

        $unjudged = Run::where('contest_id', $contest->id)
            ->whereIn('status', ['pending', 'judging'])
            ->count();

        if ($unjudged > 0) {
            $blockers[] = [
                'code' => 'unjudged_submissions',
                'message' => "{$unjudged} envio(s) ainda sem veredito.",
                'count' => $unjudged,
            ];
        }

        // CS e o veredito que a propria infraestrutura produz quando
        // desiste (ReconcileStuckRunsCommand, AutoJudgeService::
        // handleJudgingError). Finalizar com um deles de pe seria declarar
        // final uma classificacao que contem um envio que ninguem julgou --
        // e que, julgado, poderia mudar tudo.
        $judgeErrors = Run::where('contest_id', $contest->id)
            ->whereHas('answer', fn ($query) => $query->where('short_name', 'CS'))
            ->count();

        if ($judgeErrors > 0) {
            $blockers[] = [
                'code' => 'judging_errors',
                'message' => "{$judgeErrors} envio(s) com erro de julgamento. Rejulgue antes de finalizar.",
                'count' => $judgeErrors,
            ];
        }

        $pendingClarifications = Clarification::where('contest_id', $contest->id)
            ->where('status', 'pending')
            ->count();

        if ($pendingClarifications > 0) {
            $blockers[] = [
                'code' => 'unanswered_clarifications',
                'message' => "{$pendingClarifications} clarificacao(oes) sem resposta.",
                'count' => $pendingClarifications,
            ];
        }

        // Este NAO esta na lista do padrao, e e nosso.
        //
        // A premiacao deriva da classificacao final. Publicar "medalha de
        // ouro: equipe X" enquanto o placar esta congelado revela, pela
        // lista de medalhas, exatamente o que o congelamento esconde -- que
        // X esta entre os primeiros. O #189 deu o `unfrozen_at`; exigi-lo
        // aqui e o que impede o vazamento pela porta dos fundos.
        if ($contest->isFrozen()) {
            $blockers[] = [
                'code' => 'still_frozen',
                'message' => 'O placar ainda esta congelado. Revele antes de finalizar: a premiacao entregaria o que o congelamento esconde.',
            ];
        }

        if ($contest->isFinalized()) {
            $blockers[] = [
                'code' => 'already_finalized',
                'message' => 'Esta prova ja foi finalizada.',
            ];
        }

        return $blockers;
    }

    public function finalize(Contest $contest, ?User $actor): void
    {
        $contest->update([
            'finalized_at' => now(),
            'finalized_by' => $actor?->user_id,
        ]);

        // Issue #219 -- `state` muda, e o feed precisa contar. E este o
        // momento em que `end_of_updates` passa a ser verdade.
        app(ContestEventRecorder::class)->stateChanged($contest->fresh());

        ContestLog::warning($contest->id, 'Prova finalizada', [
            'event' => 'contest_finalized',
            'user_id' => $actor?->user_id,
        ]);
    }
}
