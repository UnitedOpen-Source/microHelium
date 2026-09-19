<?php

namespace App\Models;

use App\Services\BalloonService;
use App\Services\ContestClock;
use Helium\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Score extends Model
{
    use HasFactory;

    protected $fillable = [
        'contest_id',
        'user_id',
        'problem_id',
        'attempts',
        'penalty_time',
        'solved_time',
        'is_solved',
        'is_first_solver',
    ];

    protected $casts = [
        'is_solved' => 'boolean',
        'is_first_solver' => 'boolean',
    ];

    /** @return BelongsTo<Contest, $this> */
    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /** @return BelongsTo<Problem, $this> */
    public function problem(): BelongsTo
    {
        return $this->belongsTo(Problem::class);
    }

    public function getTotalTime(): int
    {
        if (! $this->is_solved) {
            return 0;
        }

        return $this->solved_time + $this->penalty_time;
    }

    public static function updateScore(Run $run): void
    {
        if (! $run->isJudged()) {
            return;
        }

        self::recomputeFor($run);
    }

    /**
     * Issue #138 -- rebuild one (contest, team, problem) cell from the runs
     * that currently count, rather than folding the new verdict into
     * whatever the cell already held.
     *
     * This used to accumulate: attempts++ on each judged run, and an early
     * `return` once the cell was solved. That is correct only while runs
     * become countable in the order they were submitted, which the
     * verification gate breaks -- a run can be judged now and released
     * later, and a later release must not be able to under- or over-count
     * the attempts that preceded it. Worked example of the accumulating
     * bug: run #1 WA is judged but withheld, run #2 AC is judged and
     * released. Accumulating gives the cell attempts=1, penalty=0; when #1
     * is verified afterwards, the cell is already solved and returns early,
     * so #1's attempt is lost and the team keeps a penalty it did not earn
     * -- silently, and in the team's favour, which is the kind of error
     * nobody reports.
     *
     * Recomputing makes the cell a pure function of the countable runs, so
     * "reappears with its original contest time and penalty the moment it
     * is verified" is true by construction and not by careful bookkeeping.
     * With verification_required off the countable set is every judged run,
     * and the result matches the accumulating version wherever runs were
     * judged in the order they were submitted. Where it does NOT match, the
     * old answer was wrong: the accumulating version let the first run to
     * be JUDGED win the cell and then returned early, so a wrong answer
     * submitted before the accepted one but judged after it never cost its
     * penalty. That is not an exotic case -- it is what a rejudge, a
     * hand-judged run, and any contest with more than one judgehost (#53)
     * produce routinely. Pinned by
     * VerdictVerificationTest::test_a_wrong_answer_judged_after_the_solve_still_costs_its_penalty.
     *
     * Ordered by contest_time (then id, to break ties deterministically):
     * ICPC penalties are counted in submission order. The old code counted
     * in *judging* order, which is the same thing only while nothing is
     * rejudged and no run waits on a human.
     */
    /**
     * A regra da celula, sozinha: dados os runs que contam, em ordem de
     * submissao, quanto vale esta celula.
     *
     * Extraida de recomputeFor() para a issue #211, onde o placar congelado
     * precisa da MESMA reducao sobre um subconjunto diferente -- so os runs
     * anteriores ao momento do congelamento. A alternativa era escrever a
     * contagem de tentativas e a penalidade de novo do lado do congelamento,
     * e duas formulacoes da mesma regra que por acaso concordam e como a
     * proxima pessoa muda uma e esquece a outra. O #171 ja documenta essa
     * armadilha neste arquivo, com um caso em que ela aconteceu.
     *
     * Pura de proposito: nao consulta nada, nao escreve nada, e por isso o
     * congelamento pode aplica-la sobre uma colecao em memoria sem que nada
     * seja gravado.
     *
     * @param  iterable<Run>  $countable  em ordem de contest_time, depois id
     * @return array{attempts: int, is_solved: bool, solved_time: int, penalty_time: int}
     */
    public static function reduceCell(iterable $countable, int $penalty, ?callable $secondsOf = null): array
    {
        // Issue #198 -- a ORDEM vem do tempo cru, o VALOR vem do ajustado.
        //
        // `$countable` ja chega ordenado por `contest_time` cru, e e assim
        // que tem que ser: dois envios feitos dentro de um intervalo
        // removido colapsam para o mesmo instante ajustado, e se a ordem
        // viesse dali eles empatariam. A spec exige o contrario -- "If
        // submission S_i arrived before submission S_j during a removed
        // interval, S_i must still be considered to have arrived strictly
        // before S_j". O valor que a celula mostra e paga, porem, e o
        // ajustado.
        $secondsOf ??= fn (Run $run) => (int) $run->contest_time;

        $attempts = 0;

        foreach ($countable as $candidate) {
            $attempts++;

            if ($candidate->answer?->is_accepted) {
                return [
                    'attempts' => $attempts,
                    'is_solved' => true,
                    'solved_time' => (int) floor($secondsOf($candidate) / 60),
                    // ICPC: so as tentativas ANTES da aceita pagam.
                    'penalty_time' => ($attempts - 1) * $penalty,
                ];
            }
        }

        // ICPC: submissoes depois da aceita nao sao contadas -- o `return`
        // acima e o que garante isso.
        return ['attempts' => $attempts, 'is_solved' => false, 'solved_time' => 0, 'penalty_time' => 0];
    }

    /**
     * Issue #317 -- quem resolveu primeiro, como CHAVE e nao como coluna.
     *
     * Dado o mesmo conjunto de runs que a celula usa, na mesma ordem, a
     * chave de comparacao da equipe neste problema: `[segundos ajustados do
     * AC, id do run]`, ou `null` se ela nao resolveu. Entre duas equipes,
     * ganha a marca a de menor chave.
     *
     * Em SEGUNDOS, de proposito. `solved_time` e minuto arredondado, e por
     * isso nao serve como chave: dois AC no mesmo minuto empatariam, e os
     * dois desempates que existiam no repositorio eram "quem gravou
     * primeiro" (placar ao vivo) e "quem aparece antes na lista de equipes"
     * (placar congelado) -- nenhum dos dois e uma regra.
     *
     * Escrita aqui, ao lado de reduceCell(), porque e a mesma pergunta sobre
     * o mesmo conjunto: o AC que a celula encontrou. Os dois placares a
     * chamam, e e isso que faz eles pararem de discordar -- ver o #211 e o
     * #171, que ja documentam esta armadilha neste arquivo.
     *
     * @param  iterable<Run>  $countable  em ordem de contest_time, depois id
     * @return array{0: int, 1: int}|null
     */
    public static function firstSolveKey(iterable $countable, ?callable $secondsOf = null): ?array
    {
        $secondsOf ??= fn (Run $run) => (int) $run->contest_time;

        foreach ($countable as $candidate) {
            if ($candidate->answer?->is_accepted) {
                return [(int) $secondsOf($candidate), (int) $candidate->id];
            }
        }

        return null;
    }

    /**
     * Issue #198 -- quanto tempo de prova um envio vale para a SEDE dele.
     *
     * Resolvido aqui e nao dentro de reduceCell() para que a reducao
     * continue pura: ela recebe uma funcao e nao um servico, e os testes da
     * regra da celula seguem sem precisar de banco.
     */
    private static function adjustedSecondsResolver(Contest $contest): callable
    {
        $clock = app(ContestClock::class);

        return fn (Run $run) => $clock->adjusted($contest, $run->site_id !== null ? (int) $run->site_id : null, (int) $run->contest_time);
    }

    public static function recomputeFor(Run $run): void
    {
        $score = self::firstOrCreate([
            'contest_id' => $run->contest_id,
            'user_id' => $run->user_id,
            'problem_id' => $run->problem_id,
        ]);

        $wasSolved = (bool) $score->is_solved;
        $contest = $run->contest;

        // Resolved once rather than per run: every candidate below is in
        // this same contest, so asking each of them for ->contest would be
        // one query per attempt for an answer that cannot differ.
        $gated = (bool) $contest?->verification_required;

        $candidates = Run::query()
            ->where('contest_id', $run->contest_id)
            ->where('user_id', $run->user_id)
            ->where('problem_id', $run->problem_id)
            ->countingTowardsScore($gated)
            ->with('answer:id,is_accepted,counts_as_attempt')
            ->orderBy('contest_time')
            ->orderBy('id')
            ->get();

        $cell = self::reduceCell(
            $candidates,
            (int) ($contest?->penalty ?? 0),
            $contest ? self::adjustedSecondsResolver($contest) : null
        );

        $attempts = $cell['attempts'];
        $isSolved = $cell['is_solved'];
        $solvedTime = $cell['solved_time'];
        $penaltyTime = $cell['penalty_time'];

        $score->attempts = $attempts;
        $score->is_solved = $isSolved;
        $score->solved_time = $solvedTime;
        $score->penalty_time = $penaltyTime;
        $score->save();

        // Issues #317/#318 -- a marca de primeiro a resolver e recalculada
        // para o PROBLEMA INTEIRO, depois de a celula estar gravada.
        //
        // Era incremental e por celula: "se ainda nao existe nenhum primeiro
        // a resolver neste problema, sou eu". Nada naquela condicao olhava
        // tempo nenhum, entao a marca ia para quem chegasse primeiro ao
        // RECALCULO -- que e coisa diferente de quem submeteu primeiro
        // sempre que houver rejulgamento, veredito retido (#138) ou mais de
        // um judgehost (#53). E, do outro lado, quando um rejulgamento
        // tirava o AC de quem tinha a marca, ela era limpa e ninguem a
        // herdava: o problema ficava sem primeiro a resolver para o resto da
        // prova, em silencio.
        //
        // Recalcular o problema inteiro resolve os dois de uma vez, sem caso
        // especial para nenhum: a marca passa a ser uma funcao dos runs que
        // contam, que e o que o placar congelado sempre fez.
        self::recomputeFirstSolver($contest, (int) $run->contest_id, (int) $run->problem_id, $gated);

        // Update leaderboard
        Leaderboard::updateForUser($run->contest_id, $run->user_id);

        if (! $wasSolved && $isSolved) {
            // Issue #87: the one point every judging path converges on --
            // the auto judge, a manual verdict, and the API's judge and
            // rejudge all reach here. Hooking the balloon anywhere else
            // would cover some of them and quietly miss the rest. Issue
            // #138 adds a fourth path, the verification that releases a
            // withheld AC, and it converges here too: a balloon carried to
            // a desk is the loudest verdict announcement there is, so it
            // must not leave before the verdict does.
            app(BalloonService::class)->awardFor($run);
        }
    }

    /**
     * Issues #317/#318 -- quem e o primeiro a resolver ESTE problema, de
     * novo, a partir dos runs.
     *
     * Uma consulta por veredito, para o problema e nao para a celula. O
     * preco e escrever em linhas de outras equipes durante o recalculo de
     * uma -- e e exatamente esse o ponto: era a ausencia dessa escrita que
     * deixava o problema sem primeiro a resolver quando um rejulgamento
     * derrubava o AC de quem tinha a marca. A equipe que passava a ser a
     * primeira nao era recalculada, porque o rejulgamento so toca as celulas
     * dos runs que ele contem.
     *
     * A regra e `firstSolveKey()`, a mesma que o placar congelado usa. Nao
     * ha um segundo `if` aqui decidindo empate: se houvesse, seria a
     * terceira formulacao da regra neste repositorio, e as duas que existiam
     * ja discordavam entre si na mesma requisicao.
     */
    private static function recomputeFirstSolver(?Contest $contest, int $contestId, int $problemId, bool $gated): void
    {
        $runs = Run::query()
            ->where('contest_id', $contestId)
            ->where('problem_id', $problemId)
            ->countingTowardsScore($gated)
            ->with('answer:id,is_accepted,counts_as_attempt')
            ->orderBy('contest_time')
            ->orderBy('id')
            ->get();

        $secondsOf = $contest ? self::adjustedSecondsResolver($contest) : null;

        $best = null;
        $winner = null;

        foreach ($runs->groupBy('user_id') as $userId => $teamRuns) {
            $key = self::firstSolveKey($teamRuns, $secondsOf);

            if ($key === null) {
                continue;
            }

            if ($best === null || $key < $best) {
                $best = $key;
                $winner = (int) $userId;
            }
        }

        // Tira de quem nao e mais (inclusive de ninguem, quando o problema
        // deixou de ter solucao) e da a quem e. As duas metades importam: a
        // primeira sozinha era o #318, a segunda sozinha era o #317.
        self::where('contest_id', $contestId)
            ->where('problem_id', $problemId)
            ->where('is_first_solver', true)
            ->when($winner !== null, fn ($query) => $query->where('user_id', '!=', $winner))
            ->update(['is_first_solver' => false]);

        if ($winner !== null) {
            self::where('contest_id', $contestId)
                ->where('problem_id', $problemId)
                ->where('user_id', $winner)
                ->where('is_first_solver', false)
                ->update(['is_first_solver' => true]);
        }
    }
}
