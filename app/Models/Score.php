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
            ->with('answer:id,is_accepted')
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

        $isFirstSolver = (bool) $score->is_first_solver;

        if (! $isSolved) {
            // A rejudge (or an un-verify) that takes the AC away takes the
            // first-solve claim with it, or the flag outlives the solve and
            // blocks the next team from ever earning it.
            $isFirstSolver = false;
        } elseif (! $isFirstSolver) {
            $isFirstSolver = ! self::where('contest_id', $run->contest_id)
                ->where('problem_id', $run->problem_id)
                ->where('is_first_solver', true)
                ->where('id', '!=', $score->id)
                ->exists();
        }

        $score->attempts = $attempts;
        $score->is_solved = $isSolved;
        $score->solved_time = $solvedTime;
        $score->penalty_time = $penaltyTime;
        $score->is_first_solver = $isFirstSolver;
        $score->save();

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
}
