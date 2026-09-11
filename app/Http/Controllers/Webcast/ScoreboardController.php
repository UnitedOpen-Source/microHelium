<?php

namespace App\Http\Controllers\Webcast;

use App\Http\Controllers\Controller;
use App\Models\Leaderboard;
use App\Models\WebcastCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The ENTIRE surface area reachable by a webcast credential (issue #44).
 * Registered outside both the `web` and `api` route groups (see
 * bootstrap/app.php's `then` callback and routes/webcast_consumer.php) so
 * it never inherits session/CSRF or any other guard's routes -- a webcast
 * credential can reach exactly this one action and nothing else.
 *
 * Always serves the live, unfrozen scoreboard for the credential's own
 * contest (Leaderboard::getScoreboard() has no freeze-cut logic -- the
 * public /scoreboard page's freeze behavior lives entirely in the
 * frontend Blade/JS layer, not here), per spec: "Role/scoped principal de
 * transmissao le placar descongelado do seu concurso e nada mais."
 */
class ScoreboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var WebcastCredential $credential */
        $credential = $request->attributes->get('webcastCredential');
        $contest = $credential->contest;

        $entries = Leaderboard::getScoreboard($contest->id);

        return response()->json([
            'contest' => [
                'id' => $contest->id,
                'name' => $contest->name,
            ],
            'generated_at' => now()->toISOString(),
            'scoreboard' => array_map(fn (array $entry) => [
                'rank' => $entry['rank'],
                'team_id' => $entry['user']->user_id ?? null,
                'team_name' => $entry['user']->fullname ?? null,
                'problems_solved' => $entry['problems_solved'],
                'total_time' => $entry['total_time'],
                'problems' => $entry['problems']->map(fn (array $p) => [
                    'short_name' => $p['short_name'],
                    'attempts' => $p['attempts'],
                    'is_solved' => $p['is_solved'],
                    'is_first_solver' => $p['is_first_solver'],
                    'solved_time' => $p['solved_time'],
                    'penalty_time' => $p['penalty_time'],
                ])->values(),
            ], $entries),
        ]);
    }
}
