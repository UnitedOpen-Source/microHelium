<?php

namespace Tests\E2E;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Leaderboard;
use App\Models\Problem;
use App\Models\Site;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end regression net for issues #64/#66: RunController::judge()/
 * rejudge() and ClarificationController::answer() previously enforced no
 * authorization beyond auth:sanctum, so any authenticated user -- including
 * the submitting team, or a judge assigned to an unrelated contest -- could
 * manipulate any run's verdict via the API. The Integration-level
 * controller tests cover each guard in isolation; this test exercises the
 * real chain a live contest depends on, through the actual HTTP API with
 * nothing mocked: a team submits, gets blocked from self-judging, a judge
 * from the wrong contest gets blocked too, and only then does the correct
 * judge's action actually flow through to Score/Leaderboard.
 */
class ApiJudgingAuthorizationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_runs_own_contest_judge_can_judge_it_via_the_api()
    {
        Storage::fake('local');

        // --- Two independent contests, each with their own judge --------
        $contestA = Contest::factory()->create(['is_active' => true, 'start_time' => now()]);
        $siteA = Site::factory()->create(['contest_id' => $contestA->id]);
        $judgeA = User::factory()->create(['user_type' => 'judge', 'contest_id' => $contestA->id]);

        $contestB = Contest::factory()->create(['is_active' => true, 'start_time' => now()]);
        $judgeB = User::factory()->create(['user_type' => 'judge', 'contest_id' => $contestB->id]);

        $team = User::factory()->create(['user_type' => 'team', 'site_id' => $siteA->id]);
        $problem = Problem::factory()->create(['contest_id' => $contestA->id, 'auto_judge' => false]);
        $language = Language::factory()->create(['contest_id' => $contestA->id, 'is_active' => true]);
        $accepted = Answer::factory()->create(['contest_id' => $contestA->id, 'short_name' => 'AC', 'is_accepted' => true]);

        // --- Team submits a real run through the API ---------------------
        Sanctum::actingAs($team);
        $submitResponse = $this->postJson('/api/runs', [
            'contest_id' => $contestA->id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'source_file' => UploadedFile::fake()->create('solution.cpp', 5, 'text/plain'),
        ]);
        $submitResponse->assertStatus(201);
        $runId = $submitResponse->json('id');
        $this->assertDatabaseHas('runs', ['id' => $runId, 'status' => 'pending']);

        // --- The team cannot judge or rejudge its own run -----------------
        $this->putJson("/api/runs/{$runId}/judge", ['answer_id' => $accepted->id])
            ->assertStatus(403);
        $this->postJson("/api/runs/{$runId}/rejudge")
            ->assertStatus(403);
        $this->assertDatabaseHas('runs', ['id' => $runId, 'status' => 'pending']);

        // --- A judge assigned to an unrelated contest is also blocked -----
        Sanctum::actingAs($judgeB);
        $this->putJson("/api/runs/{$runId}/judge", ['answer_id' => $accepted->id])
            ->assertStatus(403);
        $this->assertDatabaseHas('runs', ['id' => $runId, 'status' => 'pending']);

        // --- The run's own contest judge can judge it, and it really flows
        //     through to Score/Leaderboard -----------------------------
        Sanctum::actingAs($judgeA);
        $judgeResponse = $this->putJson("/api/runs/{$runId}/judge", ['answer_id' => $accepted->id]);
        $judgeResponse->assertStatus(200)->assertJsonPath('status', 'judged');

        $this->assertDatabaseHas('runs', [
            'id' => $runId,
            'status' => 'judged',
            'answer_id' => $accepted->id,
        ]);
        $this->assertDatabaseHas('scores', [
            'contest_id' => $contestA->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'is_solved' => true,
        ]);

        $leaderboard = Leaderboard::where('contest_id', $contestA->id)
            ->where('user_id', $team->user_id)
            ->first();
        $this->assertNotNull($leaderboard);
        $this->assertGreaterThanOrEqual(1, $leaderboard->problems_solved);

        // --- The scoreboard API reflects the same solve -------------------
        Sanctum::actingAs($judgeA);
        $scoreboard = $this->getJson("/api/contests/{$contestA->id}/scoreboard");
        $scoreboard->assertStatus(200);
        $entry = collect($scoreboard->json('scoreboard'))
            ->first(fn ($e) => $e['user']['user_id'] === $team->user_id);
        $this->assertNotNull($entry, 'the judged team must appear on its own contest scoreboard');
        $this->assertGreaterThanOrEqual(1, $entry['problems_solved']);
    }
}
