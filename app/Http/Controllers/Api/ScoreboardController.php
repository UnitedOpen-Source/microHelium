<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\Leaderboard;
use App\Models\Problem;
use App\Models\Score;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScoreboardController extends Controller
{
    public function index(Request $request, Contest $contest): JsonResponse
    {
        $frozen = $contest->isFrozen() && !auth()->user()?->isAdmin();

        $scoreboard = Leaderboard::getScoreboard($contest->id, $frozen);

        $problems = Problem::where('contest_id', $contest->id)
            ->where('is_fake', false)
            ->orderBy('sort_order')
            ->get(['id', 'short_name', 'name', 'color_name', 'color_hex']);

        return response()->json([
            'contest' => [
                'id' => $contest->id,
                'name' => $contest->name,
                'is_running' => $contest->isRunning(),
                'is_frozen' => $frozen,
                'start_time' => $contest->start_time,
                'duration' => $contest->duration,
                'penalty' => $contest->penalty,
            ],
            'problems' => $problems,
            'scoreboard' => $scoreboard,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    public function userScore(Request $request, Contest $contest): JsonResponse
    {
        $user = auth()->user();

        $scores = Score::where('contest_id', $contest->id)
            ->where('user_id', $user->user_id)
            ->with('problem:id,short_name,name')
            ->get();

        $leaderboardEntry = Leaderboard::where('contest_id', $contest->id)
            ->where('user_id', $user->user_id)
            ->first();

        return response()->json([
            'rank' => $leaderboardEntry?->rank,
            'problems_solved' => $leaderboardEntry?->problems_solved ?? 0,
            'total_time' => $leaderboardEntry?->total_time ?? 0,
            'problems' => $scores->map(fn($s) => [
                'problem_id' => $s->problem_id,
                'short_name' => $s->problem->short_name,
                'name' => $s->problem->name,
                'attempts' => $s->attempts,
                'is_solved' => $s->is_solved,
                'is_first_solver' => $s->is_first_solver,
                'solved_time' => $s->solved_time,
                'penalty_time' => $s->penalty_time,
                'total_time' => $s->getTotalTime(),
            ]),
        ]);
    }

    public function export(Request $request, Contest $contest): JsonResponse
    {
        $format = $request->get('format', 'json');
        $scoreboard = Leaderboard::getScoreboard($contest->id);

        if ($format === 'icpc') {
            return $this->exportIcpc($contest, $scoreboard);
        }

        return response()->json([
            'contest' => $contest->only(['id', 'name', 'start_time', 'duration', 'penalty']),
            'scoreboard' => $scoreboard,
            'exported_at' => now()->toIso8601String(),
        ]);
    }

    protected function exportIcpc(Contest $contest, array $scoreboard): JsonResponse
    {
        $icpcFormat = [];

        foreach ($scoreboard as $entry) {
            $icpcFormat[] = [
                'rank' => $entry['rank'],
                'team_id' => $entry['user']->icpc_id ?? $entry['user']->id,
                'team_name' => $entry['user']->name,
                'solved' => $entry['problems_solved'],
                'time' => $entry['total_time'],
            ];
        }

        return response()->json([
            'contest_id' => $contest->id,
            'contest_name' => $contest->name,
            'results' => $icpcFormat,
        ]);
    }

    public function statistics(Contest $contest): JsonResponse
    {
        $problems = Problem::where('contest_id', $contest->id)
            ->where('is_fake', false)
            ->get();

        $stats = [];

        foreach ($problems as $problem) {
            $scores = Score::where('contest_id', $contest->id)
                ->where('problem_id', $problem->id)
                ->get();

            $totalAttempts = $scores->sum('attempts');
            $solvedCount = $scores->where('is_solved', true)->count();
            $attemptedCount = $scores->count();

            $stats[] = [
                'problem_id' => $problem->id,
                'short_name' => $problem->short_name,
                'name' => $problem->name,
                'color_hex' => $problem->color_hex,
                'total_attempts' => $totalAttempts,
                'solved_count' => $solvedCount,
                'attempted_count' => $attemptedCount,
                'success_rate' => $attemptedCount > 0 ? round($solvedCount / $attemptedCount * 100, 1) : 0,
                'first_solver' => $scores->where('is_first_solver', true)->first()?->user_id,
            ];
        }

        return response()->json([
            'contest_id' => $contest->id,
            'problems' => $stats,
        ]);
    }
}