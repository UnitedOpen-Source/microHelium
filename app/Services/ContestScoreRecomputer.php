<?php

namespace App\Services;

use App\Models\Contest;
use App\Models\Run;
use App\Models\Score;

/**
 * Issue #198 -- refaz o placar inteiro de uma prova.
 *
 * E o que torna "remover um intervalo" e "desfazer a remocao" simetricos: os
 * dois sao a mesma operacao seguida do mesmo recalculo. Nenhum
 * `runs.contest_time` e reescrito, entao desfazer nao precisa lembrar de
 * nada -- e por isso a reversibilidade que a spec exige sai de graca.
 *
 * Uma celula por (equipe, problema) tocada, e nao uma por run: recompor
 * dentro do laco produziria estados intermediarios, e cada um deles dispara
 * balao e recalculo de classificacao. A mesma razao escrita em
 * RejudgingService::apply().
 */
class ContestScoreRecomputer
{
    public function recompute(Contest $contest): int
    {
        app(ContestClock::class)->forget();

        $cells = Run::where('contest_id', $contest->id)
            ->select('user_id', 'problem_id')
            ->distinct()
            ->get();

        foreach ($cells as $cell) {
            $run = Run::where('contest_id', $contest->id)
                ->where('user_id', $cell->user_id)
                ->where('problem_id', $cell->problem_id)
                ->orderBy('id')
                ->first();

            if ($run) {
                Score::recomputeFor($run);
            }
        }

        return $cells->count();
    }
}
