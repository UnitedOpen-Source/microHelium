<?php

namespace App\Models;

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

        $score = self::firstOrCreate([
            'contest_id' => $run->contest_id,
            'user_id' => $run->user_id,
            'problem_id' => $run->problem_id,
        ]);

        if ($score->is_solved) {
            return; // Already solved, no update needed
        }

        $score->attempts++;

        if ($run->isAccepted()) {
            $score->is_solved = true;
            $score->solved_time = (int) floor($run->contest_time / 60);
            $score->penalty_time = ($score->attempts - 1) * $run->contest->penalty;

            // Check if first solver
            $existingFirstSolver = self::where('contest_id', $run->contest_id)
                ->where('problem_id', $run->problem_id)
                ->where('is_first_solver', true)
                ->exists();

            if (!$existingFirstSolver) {
                $score->is_first_solver = true;
            }
        }

        $score->save();

        // Update leaderboard
        Leaderboard::updateForUser($run->contest_id, $run->user_id);
    }
}