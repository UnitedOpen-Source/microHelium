<?php

namespace App\Services\Clics;

use App\Models\Contest;
use App\Models\ContestEvent;
use App\Models\Run;
use App\Services\FrozenScoreboard;

/**
 * Issue #219 -- escreve no log o que o event feed vai contar.
 *
 * OUTBOX EXPLICITO e nao observers de model, e a escolha tem motivo.
 *
 * Observers pegam mudancas feitas por qualquer caminho, o que soa melhor --
 * mas disparam tambem em seed, em migracao e em toda factory de teste, e o
 * feed passaria a conter eventos de linhas que nunca existiram numa prova.
 * Pior: um observer nao sabe se o julgamento aconteceu dentro da janela de
 * congelamento, porque essa e uma pergunta sobre o CONTEST e sobre o tempo
 * de prova do envio, e nao sobre a linha que mudou.
 *
 * O risco do outbox e alguem esquecer de chamar. Ele e mitigado do mesmo
 * jeito que este repositorio ja resolveu o problema equivalente: chamando
 * nos pontos por onde tudo converge. Score::recomputeFor() e o exemplo que
 * o #87 documenta -- "the one point every judging path converges on" -- e
 * aqui os pontos sao AutoJudgeService::recordVerdict() para veredito,
 * RunSubmissionService para envio, e as duas transicoes de estado.
 */
class ContestEventRecorder
{
    public function __construct(private ClicsPresenter $presenter) {}

    /**
     * Um envio novo.
     *
     * Nunca marcado como da janela de congelamento: a spec esconde o
     * JULGAMENTO e nao a submissao, e e o que faz o placar congelado poder
     * mostrar uma celula pendente (#211). Um feed que escondesse o envio
     * deixaria o cliente sem saber nem que a equipe tentou.
     */
    public function submissionCreated(Run $run): void
    {
        $contest = $run->contest;

        if (! $contest) {
            return;
        }

        $this->write($contest, 'submissions', (string) $run->id, false, $this->presenter->submissions(
            $contest,
            collect([$run])
        )[0]);
    }

    /**
     * Um veredito.
     *
     * Marcado quando o ENVIO e da janela de congelamento -- e nao quando o
     * julgamento aconteceu nela. A diferenca importa: um envio feito antes
     * do corte e julgado depois continua sendo informacao anterior ao
     * congelamento, e escondê-lo esconderia o que o placar congelado ja
     * mostra.
     */
    public function judgementRecorded(Run $run): void
    {
        $contest = $run->contest;

        if (! $contest || $run->answer_id === null) {
            return;
        }

        // Issue #274 -- veredito retido nao entra no feed ainda.
        //
        // O feed grava o evento UMA vez e o reproduz depois, entao nao ha
        // como "esconder na leitura" como o /judgements faz: o que for
        // gravado aqui sai para o consumidor anonimo. A simetria disto esta
        // em Controller::markRunVerified(), que emite o evento no momento da
        // liberacao -- suprimir sem emitir depois seria pior que o defeito,
        // porque o resolver nunca veria aquele julgamento.
        if ($run->isVerdictWithheld()) {
            return;
        }

        $payload = $this->presenter->judgements($contest, collect([$run]))[0];

        $this->write($contest, 'judgements', (string) $run->id, $this->isAfterFreeze($contest, $run), $payload);
    }

    /**
     * O estado da prova mudou -- comecou, congelou, acabou, descongelou,
     * finalizou.
     *
     * Nunca escondido: `state` e o que diz ao cliente que o congelamento
     * comecou, e esconder isso seria esconder a existencia do congelamento.
     */
    public function stateChanged(Contest $contest): void
    {
        $this->write($contest, 'state', (string) $contest->id, false, $this->presenter->state($contest));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function write(Contest $contest, string $type, string $objectId, bool $afterFreeze, array $payload): void
    {
        ContestEvent::create([
            'contest_id' => $contest->id,
            'type' => $type,
            'object_id' => $objectId,
            'op' => 'create',
            'payload' => $payload,
            'after_freeze' => $afterFreeze,
            'created_at' => now(),
        ]);
    }

    private function isAfterFreeze(Contest $contest, Run $run): bool
    {
        $freezeMinutes = (int) ($contest->getAttributes()['freeze_time'] ?? 0);

        if ($freezeMinutes <= 0) {
            return false;
        }

        return (int) $run->contest_time >= FrozenScoreboard::cutoffSeconds($contest);
    }
}
