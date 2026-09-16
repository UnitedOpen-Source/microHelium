<?php

namespace App\Services;

/**
 * A regra de classificacao da ICPC, num lugar so.
 *
 * Mais resolvidos primeiro; empate em resolvidos, menos tempo primeiro;
 * empate nos dois, a mesma posicao, e a seguinte pula -- dois primeiros
 * lugares sao seguidos por um terceiro.
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
     * @param  list<array<string, mixed>>  $rows  com problems_solved e total_time
     * @return list<array<string, mixed>> ordenadas, com `rank` preenchido
     */
    public static function apply(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => [$b['problems_solved'], $a['total_time']]
            <=> [$a['problems_solved'], $b['total_time']]);

        $rank = 1;
        $previous = null;

        foreach ($rows as $index => $row) {
            $current = [$row['problems_solved'], $row['total_time']];

            if ($current !== $previous) {
                $rank = $index + 1;
            }

            $rows[$index]['rank'] = $rank;
            $previous = $current;
        }

        return $rows;
    }
}
