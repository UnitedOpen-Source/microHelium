<?php

namespace App\Services;

/**
 * A regra de classificacao da ICPC, num lugar so.
 *
 * Mais resolvidos primeiro; empate em resolvidos, menos tempo primeiro;
 * empate nos dois, o AC mais cedo primeiro; empate nos tres, a mesma
 * posicao, e a seguinte pula -- dois primeiros lugares sao seguidos por um
 * terceiro.
 *
 * Issue #316 -- o terceiro criterio e normativo e nao existia:
 *
 *   "Teams are ranked according to the most problems solved. Teams who
 *    solve the same number of problems are ranked first by least total time
 *    and, if need be, by the earliest time of submission of the last
 *    accepted run."
 *
 * Sem ele, duas equipes empatadas em resolvidos e em tempo total ficavam na
 * ordem em que o banco devolvesse as linhas -- e `ContestAwards` fatia as
 * medalhas por indice, entao a medalha de ouro saia pela ordem de criacao
 * das contas. Em SQLite aquilo parecia deterministico; em MySQL, o mesmo
 * conjunto de submissoes podia premiar equipes diferentes em dois
 * carregamentos da mesma pagina.
 *
 * `last_solved_time` e o minuto do ultimo AC SEM penalidade: a regra fala do
 * tempo de submissao, e nao do tempo consumido. Menor e melhor.
 *
 * Extraida na issue #212 porque passou a haver tres lugares precisando
 * dela: o placar ao vivo (que agora inclui equipes sem nada julgado, e por
 * isso nao pode mais so ler `leaderboard.rank`), o placar congelado (#211,
 * que recalcula tudo a partir dos runs) e Leaderboard::recalculateRanks(),
 * que grava. Tres formulacoes da mesma regra que por acaso concordam e como
 * a proxima pessoa muda uma e esquece as outras; este arquivo ja carrega
 * dois registros dessa armadilha (#171 e #211).
 */
class ScoreboardRanking
{
    /**
     * @param  list<array<string, mixed>>  $rows  com problems_solved, total_time e last_solved_time
     * @return list<array<string, mixed>> ordenadas, com `rank` preenchido
     */
    public static function apply(array $rows): array
    {
        // Issue #316 -- a ORDEM tem quatro chaves, a POSICAO tem tres.
        //
        // `user_id` no fim e o caminho (2) da issue: depois dos tres
        // criterios da regra ainda sobra empate, e o que sobrava caia na
        // ordem do banco. Ele nao entra na posicao -- equipes empatadas
        // continuam compartilhando o rank, que e o comportamento certo e que
        // o codigo ja fazia --, entra so para que a ORDEM do array seja a
        // mesma em dois carregamentos. Quem le a ordem como classificacao
        // (ContestAwards fatiando medalhas, IcpcReportBuilder,
        // ResultsBundleBuilder, o feed CLICS) passa a ler algo estavel.
        //
        // Estavel nao e o mesmo que certo: um empate que chega ate aqui e
        // uma decisao que a banca precisa tomar, e nao uma que o `user_id`
        // resolve. O que este desempate garante e que a decisao nao seja
        // tomada pelo plano de consulta, em silencio, e diferente a cada
        // carregamento.
        usort($rows, fn (array $a, array $b) => [
            $b['problems_solved'], $a['total_time'], $a['last_solved_time'] ?? 0, $a['user_id'] ?? 0,
        ] <=> [
            $a['problems_solved'], $b['total_time'], $b['last_solved_time'] ?? 0, $b['user_id'] ?? 0,
        ]);

        $rank = 1;
        $previous = null;

        foreach ($rows as $index => $row) {
            $current = [$row['problems_solved'], $row['total_time'], $row['last_solved_time'] ?? 0];

            if ($current !== $previous) {
                $rank = $index + 1;
            }

            $rows[$index]['rank'] = $rank;
            $previous = $current;
        }

        return $rows;
    }
}
