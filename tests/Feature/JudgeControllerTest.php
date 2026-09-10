<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Score;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JudgeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_user_cannot_access_the_judge_screen()
    {
        $team = $this->createTestUser(['user_type' => 'team']);

        $response = $this->actingAs($team)->get('/judge/runs');

        $response->assertStatus(403);
    }

    public function test_judge_can_view_pending_runs_for_their_contest()
    {
        $contest = Contest::factory()->create(['is_active' => true]);
        $judge = $this->createTestUser(['user_type' => 'judge', 'contest_id' => $contest->id]);
        $team = $this->createTestUser(['user_type' => 'team', 'contest_id' => $contest->id]);
        $problem = Problem::factory()->create(['contest_id' => $contest->id, 'name' => 'Sum It Up']);
        $language = Language::factory()->create(['contest_id' => $contest->id]);
        Run::factory()->create([
            'contest_id' => $contest->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($judge)->get('/judge/runs');

        $response->assertStatus(200);
        $response->assertViewIs('judge.runs');
        $response->assertSeeText('Sum It Up');
    }

    public function test_judge_can_manually_judge_a_pending_run_and_score_updates()
    {
        $contest = Contest::factory()->create(['is_active' => true, 'penalty' => 20]);
        $judge = $this->createTestUser(['user_type' => 'judge', 'contest_id' => $contest->id]);
        $team = $this->createTestUser(['user_type' => 'team', 'contest_id' => $contest->id]);
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id]);
        $accepted = Answer::factory()->create(['contest_id' => $contest->id, 'short_name' => 'AC', 'is_accepted' => true]);
        $run = Run::factory()->create([
            'contest_id' => $contest->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($judge)->post("/judge/runs/{$run->id}", [
            'answer_id' => $accepted->id,
        ]);

        $response->assertRedirect(route('judge.runs'));
        $this->assertDatabaseHas('runs', [
            'id' => $run->id,
            'status' => 'judged',
            'answer_id' => $accepted->id,
            'judge_id' => $judge->user_id,
        ]);
        $this->assertDatabaseHas('scores', [
            'contest_id' => $contest->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'is_solved' => true,
        ]);
    }

    public function test_admin_can_also_access_the_judge_screen()
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/judge/runs');

        $response->assertStatus(200);
    }

    public function test_judge_cannot_judge_a_run_from_another_contest()
    {
        $ownContest = Contest::factory()->create(['is_active' => true]);
        $judge = $this->createTestUser(['user_type' => 'judge', 'contest_id' => $ownContest->id]);

        $otherContest = Contest::factory()->create(['is_active' => true]);
        $otherTeam = $this->createTestUser(['user_type' => 'team', 'contest_id' => $otherContest->id]);
        $otherProblem = Problem::factory()->create(['contest_id' => $otherContest->id]);
        $otherLanguage = Language::factory()->create(['contest_id' => $otherContest->id]);
        $otherAnswer = Answer::factory()->create(['contest_id' => $otherContest->id, 'short_name' => 'AC', 'is_accepted' => true]);
        $otherRun = Run::factory()->create([
            'contest_id' => $otherContest->id,
            'user_id' => $otherTeam->user_id,
            'problem_id' => $otherProblem->id,
            'language_id' => $otherLanguage->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($judge)->post("/judge/runs/{$otherRun->id}", [
            'answer_id' => $otherAnswer->id,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('runs', ['id' => $otherRun->id, 'status' => 'pending']);
        $this->assertDatabaseMissing('scores', ['contest_id' => $otherContest->id, 'user_id' => $otherTeam->user_id]);
    }

    public function test_an_already_judged_run_cannot_be_judged_again_from_this_screen()
    {
        $contest = Contest::factory()->create(['is_active' => true, 'penalty' => 20]);
        $judge = $this->createTestUser(['user_type' => 'judge', 'contest_id' => $contest->id]);
        $team = $this->createTestUser(['user_type' => 'team', 'contest_id' => $contest->id]);
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id]);
        $accepted = Answer::factory()->create(['contest_id' => $contest->id, 'short_name' => 'AC', 'is_accepted' => true]);
        $wrong = Answer::factory()->create(['contest_id' => $contest->id, 'short_name' => 'WA', 'is_accepted' => false]);
        $run = Run::factory()->create([
            'contest_id' => $contest->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'status' => 'judged',
            'answer_id' => $accepted->id,
        ]);

        // Re-judging an already-judged run through this screen (rather than
        // the dedicated rejudge flow, which resets state first) would double
        // count the attempt in Score -- must be rejected.
        $response = $this->actingAs($judge)->post("/judge/runs/{$run->id}", [
            'answer_id' => $wrong->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('runs', ['id' => $run->id, 'answer_id' => $accepted->id]);
    }
}
