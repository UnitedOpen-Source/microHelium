<?php

namespace Tests\Integration\Api;

use Tests\TestCase;
use App\Models\Contest;
use App\Models\Leaderboard;
use App\Models\Problem;
use App\Models\Score;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ScoreboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_scoreboard()
    {
        $contest = Contest::factory()->create();
        $user = User::factory()->create();
        Leaderboard::factory()->create(['contest_id' => $contest->id, 'user_id' => $user->user_id]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/contests/{$contest->id}/scoreboard");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'contest',
                'problems',
                'scoreboard',
                'updated_at',
            ]);
    }

    public function test_user_score_returns_scores_for_authenticated_user()
    {
        $contest = Contest::factory()->create();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $problems = Problem::factory()->count(3)->create(['contest_id' => $contest->id]);
        foreach ($problems as $problem) {
            Score::factory()->create([
                'contest_id' => $contest->id,
                'user_id' => $user->user_id,
                'problem_id' => $problem->id,
            ]);
        }
        Leaderboard::factory()->create(['contest_id' => $contest->id, 'user_id' => $user->user_id]);

        $response = $this->getJson("/api/contests/{$contest->id}/my-score");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'problems');
    }

    public function test_export_returns_json_scoreboard()
    {
        $contest = Contest::factory()->create();
        Sanctum::actingAs(User::factory()->create(['user_type' => 'admin']));
        $response = $this->getJson("/api/contests/{$contest->id}/scoreboard/export?format=json");

        $response->assertStatus(200)
            ->assertJsonStructure(['contest', 'scoreboard', 'exported_at']);
    }

    public function test_export_returns_icpc_format_scoreboard()
    {
        $contest = Contest::factory()->create();
        $user = User::factory()->create();
        Leaderboard::factory()->create([
            'contest_id' => $contest->id,
            'user_id' => $user->user_id,
        ]);
        Sanctum::actingAs(User::factory()->create(['user_type' => 'admin']));
        $response = $this->getJson("/api/contests/{$contest->id}/scoreboard/export?format=icpc");
        $response->assertStatus(200)
            ->assertJsonStructure(['contest_id', 'contest_name', 'results']);
    }

    public function test_statistics_returns_problem_statistics()
    {
        $contest = Contest::factory()->create();
        Problem::factory()->count(2)->create(['contest_id' => $contest->id]);
        Sanctum::actingAs(User::factory()->create(['user_type' => 'admin']));

        $response = $this->getJson("/api/contests/{$contest->id}/statistics");

        $response->assertStatus(200)
            ->assertJsonStructure(['contest_id', 'problems'])
            ->assertJsonCount(2, 'problems');
    }
}