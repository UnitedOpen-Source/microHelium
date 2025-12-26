<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Leaderboard extends Model
{
    use HasFactory;

    protected $table = 'leaderboard';

    protected $fillable = [
        'contest_id',
        'user_id',
        'problems_solved',
        'total_time',
        'rank',
    ];

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\Helium\User::class, 'user_id', 'user_id');
    }

    public static function updateForUser(int $contestId, int $userId): void
    {
        $stats = Score::where('contest_id', $contestId)
            ->where('user_id', $userId)
            ->where('is_solved', true)
            ->selectRaw('COUNT(*) as solved, SUM(solved_time + penalty_time) as total_time')
            ->first();

        self::updateOrCreate(
            ['contest_id' => $contestId, 'user_id' => $userId],
            [
                'problems_solved' => $stats->solved ?? 0,
                'total_time' => $stats->total_time ?? 0,
            ]
        );

        self::recalculateRanks($contestId);
    }

    public static function recalculateRanks(int $contestId): void
    {
        $entries = self::where('contest_id', $contestId)
            ->orderByDesc('problems_solved')
            ->orderBy('total_time')
            ->get();

        $rank = 1;
        $prevSolved = null;
        $prevTime = null;

        foreach ($entries as $index => $entry) {
            if ($entry->problems_solved !== $prevSolved || $entry->total_time !== $prevTime) {
                $rank = $index + 1;
            }

            $entry->rank = $rank;
            $entry->save();

            $prevSolved = $entry->problems_solved;
            $prevTime = $entry->total_time;
        }
    }

    public static function getScoreboard(int $contestId, bool $frozen = false): array
    {
        $contest = Contest::findOrFail($contestId);

        $query = self::where('contest_id', $contestId)
            ->with(['user'])
            ->orderBy('rank');

        $leaderboard = $query->get();

        $result = [];
        foreach ($leaderboard as $entry) {
            $scores = Score::where('contest_id', $contestId)
                ->where('user_id', $entry->user_id)
                ->with('problem')
                ->get()
                ->keyBy('problem_id');

            $result[] = [
                'rank' => $entry->rank,
                'user' => $entry->user,
                'problems_solved' => $entry->problems_solved,
                'total_time' => $entry->total_time,
                'problems' => $scores->map(fn($s) => [
                    'problem_id' => $s->problem_id,
                    'short_name' => $s->problem->short_name,
                    'attempts' => $s->attempts,
                    'is_solved' => $s->is_solved,
                    'is_first_solver' => $s->is_first_solver,
                    'solved_time' => $s->solved_time,
                    'penalty_time' => $s->penalty_time,
                ])->values(),
            ];
        }

        return $result;
    }
}
