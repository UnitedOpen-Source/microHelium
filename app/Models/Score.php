<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Services\BalloonService;
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

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\Helium\User::class, 'user_id', 'user_id');
    }

    public function problem(): BelongsTo
    {
        return $this->belongsTo(Problem::class);
    }

    public function getTotalTime(): int
    {
        if (!$this->is_solved) {
            return 0;
        }
        return $this->solved_time + $this->penalty_time;
    }

    public static function updateScore(Run $run): void
    {
        if (!$run->isJudged()) {
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
            ->where('status', 'judged')
            ->whereNotNull('answer_id')
            ->when($gated, fn ($q) => $q->whereNotNull('verified_at'))
            ->with('answer:id,is_accepted')
            ->orderBy('contest_time')
            ->orderBy('id')
            ->get();

        $attempts = 0;
        $isSolved = false;
        $solvedTime = 0;
        $penaltyTime = 0;

        foreach ($candidates as $candidate) {
            $attempts++;

            if ($candidate->answer?->is_accepted) {
                $isSolved = true;
                $solvedTime = (int) floor($candidate->contest_time / 60);
                $penaltyTime = ($attempts - 1) * (int) ($contest?->penalty ?? 0);
                // ICPC: submissions after the accepted one are not counted.
                break;
            }
        }

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