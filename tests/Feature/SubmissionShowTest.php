<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubmissionShowTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(array $overrides = []): Run
    {
        $contest = Contest::factory()->create();
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id]);
        $answer = Answer::factory()->create(['contest_id' => $contest->id, 'short_name' => 'AC', 'is_accepted' => true]);

        return Run::factory()->create($overrides + [
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'answer_id' => $answer->id,
            'status' => 'judged',
            'source_file' => 'sources/does-not-exist.c',
        ]);
    }

    public function test_owner_can_view_their_submission()
    {
        $owner = $this->createTestUser(['user_type' => 'team']);
        $run = $this->makeRun(['user_id' => $owner->user_id]);

        $response = $this->actingAs($owner)->get("/submission/{$run->id}");

        $response->assertStatus(200);
        $response->assertViewIs('submission-show');
    }

    public function test_another_team_cannot_view_someone_elses_submission()
    {
        $owner = $this->createTestUser(['user_type' => 'team']);
        $other = $this->createTestUser(['user_type' => 'team']);
        $run = $this->makeRun(['user_id' => $owner->user_id]);

        $response = $this->actingAs($other)->get("/submission/{$run->id}");

        $response->assertStatus(403);
    }

    public function test_admin_can_view_any_submission()
    {
        $owner = $this->createTestUser(['user_type' => 'team']);
        $admin = $this->createAdminUser();
        $run = $this->makeRun(['user_id' => $owner->user_id]);

        $response = $this->actingAs($admin)->get("/submission/{$run->id}");

        $response->assertStatus(200);
    }
}
