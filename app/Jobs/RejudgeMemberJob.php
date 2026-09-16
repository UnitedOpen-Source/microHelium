<?php

namespace App\Jobs;

use App\Models\Answer;
use App\Models\RejudgingRun;
use App\Services\AutoJudgeService;
use App\Services\RejudgingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Issue #192 -- julga UM membro do conjunto sem tocar no run.
 *
 * O julgamento de sombra e o que torna a previa possivel: o resultado vai
 * para a linha de rejudging_runs, e o run continua exatamente como estava.
 * Enquanto a organizacao decide, a equipe ve o veredito que ja via e a
 * classificacao nao se mexe.
 *
 * Carrega o ID e nao o modelo: entre enfileirar e rodar, o conjunto pode ter
 * sido cancelado, e uma instancia serializada nao saberia disso.
 */
class RejudgeMemberJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $memberId) {}

    public function handle(AutoJudgeService $judge, RejudgingService $rejudgings): void
    {
        $member = RejudgingRun::with(['rejudging', 'run.problem', 'run.language'])->find($this->memberId);

        if (! $member || ! $member->run) {
            return;
        }

        // Cancelado no meio da preparacao: parar aqui poupa julgar dezenas
        // de envios cujo resultado ninguem vai ler.
        if (! $member->rejudging?->isOpen()) {
            return;
        }

        try {
            $result = $judge->judgeWithoutPersisting($member->run);
            $verdict = $result['verdict'] ?? null;

            $answer = $verdict
                ? Answer::where('contest_id', $member->run->contest_id)->where('short_name', $verdict)->first()
                : null;

            if (! $answer) {
                // Veredito sem linha de `answers` correspondente. Gravar
                // answer_id null e marcar judged deixaria o run num estado
                // que Score::updateScore() ignora -- julgado, sem resposta,
                // fora do placar e invisivel para a equipe. Melhor tratar
                // como falha do membro e nao mexer nele.
                $member->update([
                    'error' => "O veredito \"{$verdict}\" nao corresponde a nenhuma resposta cadastrada neste contest.",
                    'judged_at' => now(),
                ]);

                $rejudgings->refreshReadiness($member->rejudging);

                return;
            }

            $member->update([
                'new_answer_id' => $answer->id,
                'new_verdict' => $verdict,
                'new_message' => $result['message'] ?? null,
                'judged_at' => now(),
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            // A falha fica NO MEMBRO, e nao no conjunto. Um envio que nao
            // compila mais porque o pacote mudou nao pode impedir que os
            // outros trezentos sejam decididos -- e aplicar nunca toca num
            // membro com erro, entao o veredito antigo dele fica de pe.
            $member->update([
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'judged_at' => now(),
            ]);
        }

        $rejudgings->refreshReadiness($member->rejudging->fresh());
    }
}
