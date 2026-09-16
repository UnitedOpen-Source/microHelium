<?php

namespace App\Services;

use App\Models\Contest;
use App\Models\Leaderboard;
use App\Models\Score;

/**
 * Issue #202 -- colocacao com o corte da mediana, e as medalhas que derivam
 * dela.
 *
 * A regra do corte e normativa na ICPC:
 *
 *   "Teams that solved fewer problems than the median team are not ranked
 *    at all."
 *
 * E ela e mais dura do que parece: nao e "aparecem no fim", e "nao sao
 * classificadas". Uma equipe abaixo da mediana nao tem colocacao; o que ela
 * tem e mencao honrosa.
 *
 * Configuravel por contest (`rank_median_cut`), porque a issue pergunta e a
 * resposta e sim: uma prova de treino ou uma seletiva interna quer
 * classificar todo mundo, e aplicar a mediana la deixaria metade da turma
 * sem colocacao por um motivo que nao existe naquele contexto. O padrao e o
 * da ICPC.
 */
class ContestAwards
{
    /**
     * @return array<string, mixed>
     */
    public function forContest(Contest $contest): array
    {
        $rows = Leaderboard::getScoreboard($contest->id);
        $median = $this->medianSolved($rows);
        $cut = (bool) $contest->rank_median_cut;

        $ranked = [];
        $honorable = [];

        foreach ($rows as $row) {
            $solved = (int) $row['problems_solved'];

            // Menos que a mediana -> fora da classificacao. Igual a mediana
            // continua classificada: "fewer than the median", e nao "at most
            // the median". A diferenca decide o destino da metade do meio da
            // tabela, que numa regional sao dezenas de equipes.
            if ($cut && $solved < $median) {
                $honorable[] = $row;

                continue;
            }

            $ranked[] = $row;
        }

        return [
            'finalized' => $contest->isFinalized(),
            'finalized_at' => $contest->finalized_at?->toISOString(),
            'median_solved' => $median,
            'median_cut_applied' => $cut,
            'awards' => $this->awards($contest, $ranked, $honorable),
        ];
    }

    /**
     * A mediana de problemas resolvidos entre as equipes.
     *
     * Conta TODAS as equipes do placar, inclusive as que nao resolveram
     * nada -- sao competidoras, e tirar os zeros da conta subiria a mediana
     * e desclassificaria gente que a regra nao manda desclassificar. Com o
     * #212 essas equipes passaram a aparecer no placar, entao a conta aqui
     * so esta certa depois dele.
     *
     * Numero par de equipes: a media dos dois centrais, como manda a
     * definicao. A ICPC nao especifica o desempate, e a media e o que as
     * outras implementacoes fazem.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function medianSolved(array $rows): float
    {
        if ($rows === []) {
            return 0.0;
        }

        $solved = array_map(fn (array $row) => (int) $row['problems_solved'], $rows);
        sort($solved);

        $count = count($solved);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $solved[$middle]
            : ($solved[$middle - 1] + $solved[$middle]) / 2;
    }

    /**
     * Os premios, no vocabulario que a Contest API usa em /awards.
     *
     * @param  list<array<string, mixed>>  $ranked
     * @param  list<array<string, mixed>>  $honorable
     * @return list<array{id: string, citation: string, team_ids: list<int>}>
     */
    private function awards(Contest $contest, array $ranked, array $honorable): array
    {
        $awards = [];

        if ($ranked !== []) {
            $awards[] = $this->award('winner', 'Campeao', [$ranked[0]]);
        }

        $medals = [
            'gold-medal' => ['Medalha de ouro', (int) $contest->medal_gold],
            'silver-medal' => ['Medalha de prata', (int) $contest->medal_silver],
            'bronze-medal' => ['Medalha de bronze', (int) $contest->medal_bronze],
        ];

        $offset = 0;

        foreach ($medals as $id => [$citation, $count]) {
            // Sem `if ($count <= 0) continue;` antes desta linha: com zero,
            // array_slice devolve vazio e o teste abaixo ja pula. A guarda
            // existia e uma mutacao mostrou que remove-la nao derrubava
            // teste nenhum -- porque era redundante, nao porque faltava
            // cobertura. Uma linha que parece proteger e nao protege e pior
            // do que a sua ausencia: a proxima pessoa confia nela.
            $slice = array_slice($ranked, $offset, $count);
            $offset += $count;

            if ($slice !== []) {
                $awards[] = $this->award($id, $citation, $slice);
            }
        }

        // rank-<n> por posicao, e nao por indice: equipes empatadas
        // compartilham a posicao (ScoreboardRanking), entao duas linhas
        // podem ser rank-1 -- e serem duas primeiras colocadas e o que
        // aconteceu de verdade.
        $byRank = [];

        foreach ($ranked as $row) {
            $byRank[(int) $row['rank']][] = $row;
        }

        foreach ($byRank as $rank => $rows) {
            $awards[] = $this->award("rank-{$rank}", "{$rank}o lugar", $rows);
        }

        if ($honorable !== []) {
            $awards[] = $this->award('honorable-mention', 'Mencao honrosa', $honorable);
        }

        foreach ($this->firstToSolve($contest) as $problemId => $teamIds) {
            $awards[] = [
                'id' => "first-to-solve-{$problemId}",
                'citation' => 'Primeiro a resolver',
                'team_ids' => $teamIds,
            ];
        }

        return $awards;
    }

    /**
     * Quem resolveu cada problema primeiro.
     *
     * Vem de `scores.is_first_solver`, que ja existia e que o #171 corrigiu
     * para nao sobreviver a um rejulgamento que tira o AC -- sem aquele
     * conserto, a premiacao aqui citaria uma equipe que nao resolveu mais.
     *
     * @return array<int, list<int>>
     */
    private function firstToSolve(Contest $contest): array
    {
        $result = [];

        $rows = Score::where('contest_id', $contest->id)
            ->where('is_first_solver', true)
            ->orderBy('problem_id')
            ->get(['problem_id', 'user_id']);

        foreach ($rows as $row) {
            $result[(int) $row->problem_id][] = (int) $row->user_id;
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{id: string, citation: string, team_ids: list<int>}
     */
    private function award(string $id, string $citation, array $rows): array
    {
        return [
            'id' => $id,
            'citation' => $citation,
            'team_ids' => array_values(array_map(fn (array $row) => (int) $row['user']->user_id, $rows)),
        ];
    }
}
