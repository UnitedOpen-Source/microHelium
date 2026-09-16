<?php

namespace App\Services;

use App\Models\Contest;
use App\Models\Leaderboard;
use App\Models\Run;
use App\Models\Score;
use Illuminate\Support\Collection;

/**
 * Issue #211 -- o placar como ele era no momento do congelamento, mais as
 * submissoes posteriores marcadas como pendentes.
 *
 * O #189 consertou QUANDO Contest::isFrozen() e verdadeiro. Ninguem havia
 * conferido o que era feito com a resposta, e a resposta nao era usada:
 * Leaderboard::getScoreboard() recebia `$frozen` e nunca o lia. A API
 * respondia `is_frozen: true` e entregava o solve pos-congelamento na mesma
 * resposta -- medido, nao lido. Na ultima hora de prova toda equipe e todo
 * espectador via o placar ao vivo.
 *
 * O congelamento e um CORTE POR TEMPO DE PROVA, e nao uma coluna. As
 * celulas sao recalculadas contando so os runs com contest_time anterior ao
 * momento do congelamento, e por isso nada do que esta guardado em `scores`
 * serve aqui: aquelas linhas sao atualizadas a cada veredito.
 *
 * A celula congelada NAO e "sem informacao". A ICPC mostra as tentativas
 * feitas durante o congelamento como pendentes -- a celula fica em aberto,
 * com quantas -- e e isso que faz a revelacao ter graca. Esconder a
 * existencia da submissao seria um congelamento diferente, e pior: a equipe
 * nao saberia nem que o adversario tentou.
 */
class FrozenScoreboard
{
    /**
     * As linhas do placar congelado deste contest, no mesmo formato de
     * Leaderboard::getScoreboard().
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(Contest $contest): array
    {
        $cutoff = self::cutoffSeconds($contest);

        // A MESMA porta que Score::recomputeFor() usa. Um veredito retido
        // da equipe (#138) tambem nao pode ser contado para ela aqui.
        $gated = (bool) $contest->verification_required;
        $penalty = (int) $contest->penalty;

        // Uma consulta para o contest inteiro, e nao uma por equipe: o
        // caminho ao vivo ja faz uma consulta de `scores` por linha do
        // leaderboard, e aqui seriam duas.
        $runs = Run::query()
            ->where('contest_id', $contest->id)
            ->with(['answer:id,is_accepted', 'problem:id,short_name'])
            ->orderBy('contest_time')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');

        // As equipes vem do leaderboard e nao dos runs, para que o conjunto
        // de linhas seja o mesmo do placar ao vivo: uma equipe que ainda nao
        // submeteu nada aparece com zero, como aparece hoje.
        $entries = Leaderboard::where('contest_id', $contest->id)->with('user')->get();

        $rows = [];

        foreach ($entries as $entry) {
            $rows[] = self::row($entry, $runs->get($entry->user_id, collect()), $cutoff, $gated, $penalty);
        }

        return self::rank(self::markFirstSolvers($rows));
    }

    /**
     * O segundo em que o congelamento comeca, contado do inicio da prova.
     *
     * `freeze_time` na coluna e "minutos antes do fim" (o acessor do modelo
     * devolve o instante absoluto, que nao serve para comparar com
     * contest_time, que e em segundos desde o inicio).
     */
    public static function cutoffSeconds(Contest $contest): int
    {
        $freezeMinutes = (int) ($contest->getAttributes()['freeze_time'] ?? 0);

        return max(0, ((int) $contest->duration - $freezeMinutes) * 60);
    }

    /**
     * @param  Collection<int, Run>  $teamRuns
     * @return array<string, mixed>
     */
    private static function row(Leaderboard $entry, Collection $teamRuns, int $cutoff, bool $gated, int $penalty): array
    {
        $problems = [];
        $solved = 0;
        $totalTime = 0;

        foreach ($teamRuns->groupBy('problem_id') as $problemId => $attempts) {
            $cell = self::cell($attempts, $cutoff, $gated, $penalty);

            if ($cell['attempts'] === 0 && $cell['pending'] === 0) {
                continue;
            }

            if ($cell['is_solved']) {
                $solved++;
                $totalTime += $cell['solved_time'] + $cell['penalty_time'];
            }

            $problems[] = ['problem_id' => (int) $problemId] + $cell;
        }

        return [
            'rank' => 0,
            'user' => $entry->user,
            'problems_solved' => $solved,
            'total_time' => $totalTime,
            'problems' => collect($problems),
        ];
    }

    /**
     * @param  Collection<int, Run>  $attempts
     * @return array{short_name: ?string, attempts: int, is_solved: bool, is_first_solver: bool, solved_time: int, penalty_time: int, pending: int}
     */
    private static function cell(Collection $attempts, int $cutoff, bool $gated, int $penalty): array
    {
        $visible = $attempts->filter(fn (Run $run) => (int) $run->contest_time < $cutoff);

        $countable = $visible->filter(
            fn (Run $run) => $run->status === 'judged'
                && $run->answer_id !== null
                && (! $gated || $run->verified_at !== null)
        );

        $cell = Score::reduceCell($countable, $penalty);

        // Resolvida antes do corte: a celula e identica a do placar ao vivo,
        // e nada depois dela conta -- nem como pendente. Mostrar "resolvido
        // + 3 pendentes" diria que a equipe continuou submetendo um problema
        // que ja tinha passado, o que a ICPC nao conta e a equipe nao fez.
        $pending = $cell['is_solved']
            ? 0
            : $attempts->count() - $countable->count();

        return [
            'short_name' => $attempts->first()?->problem?->short_name,
            'attempts' => $cell['attempts'],
            'is_solved' => $cell['is_solved'],
            'is_first_solver' => false,
            'solved_time' => $cell['solved_time'],
            'penalty_time' => $cell['penalty_time'],
            'pending' => $pending,
        ];
    }

    /**
     * Quem resolveu primeiro DENTRO do que o placar congelado mostra.
     *
     * Deliberadamente recalculado em vez de lido de `scores.is_first_solver`:
     * a marca guardada pode ter sido conquistada durante o congelamento, e
     * mostra-la entregaria o solve que o congelamento esconde -- uma
     * medalha e um anuncio de veredito tanto quanto uma celula verde.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function markFirstSolvers(array $rows): array
    {
        $best = [];

        foreach ($rows as $index => $row) {
            foreach ($row['problems'] as $cell) {
                if (! $cell['is_solved']) {
                    continue;
                }

                $problemId = $cell['problem_id'];
                $key = [$cell['solved_time'], $index];

                if (! isset($best[$problemId]) || $key < $best[$problemId]) {
                    $best[$problemId] = $key;
                }
            }
        }

        foreach ($rows as $index => $row) {
            $rows[$index]['problems'] = $row['problems']->map(function (array $cell) use ($best, $index) {
                $cell['is_first_solver'] = $cell['is_solved']
                    && ($best[$cell['problem_id']] ?? null) === [$cell['solved_time'], $index];

                return $cell;
            });
        }

        return $rows;
    }

    /**
     * A mesma regra de Leaderboard::recalculateRanks(): empate compartilha a
     * posicao, e a seguinte pula. As posicoes TEM que ser recalculadas --
     * as guardadas refletem o placar ao vivo, e servi-las junto de celulas
     * congeladas seria dizer, pela ordem, o que as celulas esconderam.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function rank(array $rows): array
    {
        usort($rows, function (array $a, array $b) {
            return [$b['problems_solved'], $a['total_time']] <=> [$a['problems_solved'], $b['total_time']];
        });

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
