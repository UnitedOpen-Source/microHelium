<?php

namespace App\Services;

use App\Models\Contest;
use App\Models\Run;
use App\Models\Score;
use Helium\User;
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
        // Issue #319 -- o corte e uma funcao do run, e nao um numero.
        //
        // O #276 ligou duracao e congelamento proprios por sede e
        // `ContestClock` passou a responder corretamente QUANDO cada sede
        // congela. O corte, que decide O QUE o placar esconde, continuou
        // global: uma sede com janela mais curta ja tinha congelado e tinha
        // a ultima hora inteira dela no telao, enquanto ainda competia.
        //
        // Memoizado por sede porque a pergunta e feita uma vez por run, e
        // sao milhares numa regional; a resposta so depende da sede.
        $cutoffCache = [];
        $cutoffOf = function (Run $run) use ($contest, &$cutoffCache): int {
            $siteId = $run->site_id !== null ? (int) $run->site_id : null;
            $key = $siteId ?? 0;

            return $cutoffCache[$key] ??= self::cutoffSeconds($contest, $siteId);
        };

        // A MESMA porta que Score::recomputeFor() usa. Um veredito retido
        // da equipe (#138) tambem nao pode ser contado para ela aqui.
        $gated = (bool) $contest->verification_required;
        $penalty = (int) $contest->penalty;

        // Uma consulta para o contest inteiro, e nao uma por equipe: o
        // caminho ao vivo ja faz uma consulta de `scores` por linha do
        // leaderboard, e aqui seriam duas.
        $runs = Run::query()
            ->where('contest_id', $contest->id)
            // `counts_as_attempt` no select da relacao nao e opcional:
            // Run::countsTowardsScore() o exige (#321).
            ->with(['answer:id,is_accepted,counts_as_attempt', 'problem:id,short_name'])
            ->orderBy('contest_time')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');

        $clock = app(ContestClock::class);
        $secondsOf = fn (Run $run) => $clock->adjusted($contest, $run->site_id !== null ? (int) $run->site_id : null, (int) $run->contest_time);

        // Issue #212 -- o mesmo conjunto de equipes do placar ao vivo, e
        // pela mesma fonte. Era o leaderboard, que so tem linha para quem ja
        // teve run julgado: os dois placares mostrariam times diferentes, e
        // durante o congelamento essa diferenca seria lida como informacao.
        $rows = [];

        foreach (ScoreboardTeams::forContest($contest) as $userId => $user) {
            $rows[] = self::row($user, $runs->get($userId, collect()), $cutoffOf, $gated, $penalty, $secondsOf);
        }

        return ScoreboardRanking::apply(self::markFirstSolvers($rows));
    }

    /**
     * O segundo em que o congelamento comeca, contado do inicio da prova.
     *
     * `freeze_time` na coluna e "minutos antes do fim" (o acessor do modelo
     * devolve o instante absoluto, que nao serve para comparar com
     * contest_time, que e em segundos desde o inicio).
     *
     * Issue #319 -- com sede, a janela DELA.
     *
     * `$siteId` e opcional e o `null` devolve exatamente o numero de antes
     * (`ContestClock` cai no contest para os dois valores quando nao ha
     * sede), o que mantem os tres chamadores CLICS funcionando como hoje.
     * Eles decidem por instante absoluto ou por run sem saber de sede, e
     * mudar isso e outra conversa -- registrada na issue; aqui o que se
     * conserta e o placar, que e quem revela.
     */
    public static function cutoffSeconds(Contest $contest, ?int $siteId = null): int
    {
        $clock = app(ContestClock::class);

        $duration = $clock->durationMinutesFor($contest, $siteId);
        $freezeMinutes = $clock->freezeMinutesFor($contest, $siteId);

        return max(0, ($duration - $freezeMinutes) * 60);
    }

    /**
     * @param  Collection<int, Run>  $teamRuns
     * @return array<string, mixed>
     */
    private static function row(User $user, Collection $teamRuns, callable $cutoffOf, bool $gated, int $penalty, callable $secondsOf): array
    {
        $problems = [];
        $solved = 0;
        $totalTime = 0;
        $lastSolvedTime = 0;

        foreach ($teamRuns->groupBy('problem_id') as $problemId => $attempts) {
            $cell = self::cell($attempts, $cutoffOf, $gated, $penalty, $secondsOf);

            if ($cell['attempts'] === 0 && $cell['pending'] === 0) {
                continue;
            }

            if ($cell['is_solved']) {
                $solved++;
                $totalTime += $cell['solved_time'] + $cell['penalty_time'];
                // Issue #316 -- o terceiro criterio: o minuto do ultimo AC,
                // sem penalidade. Calculado do que o congelamento MOSTRA,
                // como todo o resto desta linha; um AC escondido nao pode
                // desempatar o placar publico, ou o desempate seria a
                // revelacao.
                $lastSolvedTime = max($lastSolvedTime, (int) $cell['solved_time']);
            }

            $problems[] = ['problem_id' => (int) $problemId] + $cell;
        }

        return [
            'rank' => 0,
            'user' => $user,
            'user_id' => (int) $user->user_id,
            'problems_solved' => $solved,
            'total_time' => $totalTime,
            'last_solved_time' => $lastSolvedTime,
            'problems' => collect($problems),
        ];
    }

    /**
     * @param  Collection<int, Run>  $attempts
     * @return array{short_name: ?string, attempts: int, is_solved: bool, is_first_solver: bool, solved_time: int, penalty_time: int, pending: int, first_solve_key: ?array{0: int, 1: int}}
     */
    private static function cell(Collection $attempts, callable $cutoffOf, bool $gated, int $penalty, callable $secondsOf): array
    {
        // Issue #198 -- o corte e comparado em tempo AJUSTADO.
        //
        // Com um intervalo removido da prova inteira, o congelamento comeca
        // quando as equipes tiverem vivido `duration - freeze` de tempo QUE
        // CONTA, e nao de relogio de parede. Comparar cru deixaria o
        // congelamento comecar cedo demais por exatamente o tanto que foi
        // removido.
        // Issue #319 -- o corte da SEDE do run, e nao o da prova.
        $visible = $attempts->filter(
            fn (Run $run) => $secondsOf($run) < $cutoffOf($run)
        );

        // Issue #321 -- a mesma regra do placar ao vivo, perguntada ao run
        // em vez de ao banco. Estava reescrita por extenso aqui.
        $countable = $visible->filter(
            fn (Run $run) => $run->countsTowardsScore($gated)
        );

        $cell = Score::reduceCell($countable, $penalty, $secondsOf);

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
            // Issue #317 -- a chave de comparacao do "primeiro a resolver",
            // em SEGUNDOS. `solved_time` e minuto arredondado e nao serve:
            // dois AC no mesmo minuto empatavam e o desempate caia no
            // indice da linha, ou seja, na ordem da lista de equipes.
            // Interna a este arquivo: markFirstSolvers() a consome e a
            // remove antes de a celula sair daqui.
            'first_solve_key' => $cell['is_solved']
                ? Score::firstSolveKey($countable, $secondsOf)
                : null,
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
     * Issue #317 -- a comparacao e em SEGUNDOS, pela chave que
     * `Score::firstSolveKey()` devolve. Era `[solved_time, indice da linha]`,
     * e `solved_time` esta em minutos: duas equipes que resolvessem no mesmo
     * minuto empatavam e a marca ia para quem aparecesse antes na lista de
     * equipes. A equipe que chegou 40 segundos antes perdia a marca para a
     * ordem de um `ORDER BY` que nao existe.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function markFirstSolvers(array $rows): array
    {
        $best = [];

        foreach ($rows as $row) {
            foreach ($row['problems'] as $cell) {
                if ($cell['first_solve_key'] === null) {
                    continue;
                }

                $problemId = $cell['problem_id'];

                if (! isset($best[$problemId]) || $cell['first_solve_key'] < $best[$problemId]) {
                    $best[$problemId] = $cell['first_solve_key'];
                }
            }
        }

        foreach ($rows as $index => $row) {
            $rows[$index]['problems'] = $row['problems']->map(function (array $cell) use ($best) {
                $cell['is_first_solver'] = $cell['first_solve_key'] !== null
                    && ($best[$cell['problem_id']] ?? null) === $cell['first_solve_key'];

                // Chave de trabalho, nao dado de placar: sai antes de a
                // linha chegar a quem consome.
                unset($cell['first_solve_key']);

                return $cell;
            });
        }

        return $rows;
    }
}
