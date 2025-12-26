<?php

namespace Tests\Integration\Api;

use Tests\TestCase;
use App\Models\Run;
use App\Models\Contest;
use App\Models\Problem;
use App\Models\Language;
use App\Models\Answer;
use App\Jobs\JudgeRunJob;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use App\Models\Site;

class RunControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_paginated_runs()
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        Sanctum::actingAs($admin);
        $contest = Contest::factory()->create();
        Run::factory()->count(10)->create(['contest_id' => $contest->id]);
        $response = $this->getJson("/api/runs?contest_id={$contest->id}");
        $response->assertStatus(200)->assertJsonCount(10, 'data');
    }

    public function test_store_creates_run_and_dispatches_job()
    {
        Queue::fake();
        Storage::fake('local');
        $site = Site::factory()->create();
        $contest = $site->contest;
        $contest->update(['is_active' => true, 'start_time' => now()]);
        $user = User::factory()->create(['site_id' => $site->id]);
        Sanctum::actingAs($user);

        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id, 'is_active' => true]);
        $file = UploadedFile::fake()->create('solution.cpp', 10, 'text/plain');

        $response = $this->postJson('/api/runs', [
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'source_file' => $file,
        ]);

        $response->assertStatus(201);
        Queue::assertPushed(JudgeRunJob::class);
    }

    public function test_store_fails_if_contest_not_running()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()->addHour()]);
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id, 'is_active' => true]);
        $file = UploadedFile::fake()->create('solution.cpp', 10, 'text/plain');

        $response = $this->postJson('/api/runs', [
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'source_file' => $file,
        ]);

        $response->assertStatus(422);
    }

    public function test_store_fails_if_problem_not_in_contest()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()]);
        $otherContest = Contest::factory()->create();
        $problem = Problem::factory()->create(['contest_id' => $otherContest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id, 'is_active' => true]);
        $file = UploadedFile::fake()->create('solution.cpp', 10, 'text/plain');

        $response = $this->postJson('/api/runs', [
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'source_file' => $file,
        ]);

        $response->assertStatus(422);
    }

    public function test_store_fails_if_language_not_in_contest()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()]);
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $otherContest = Contest::factory()->create();
        $language = Language::factory()->create(['contest_id' => $otherContest->id, 'is_active' => true]);
        $file = UploadedFile::fake()->create('solution.cpp', 10, 'text/plain');

        $response = $this->postJson('/api/runs', [
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'source_file' => $file,
        ]);

        $response->assertStatus(422);
    }

    public function test_store_fails_on_duplicate_submission()
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('solution.cpp', 10, 'text/plain');
        $path = $file->store('temp');
        $hash = hash_file('sha256', Storage::path($path));
        
        $contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id, 'is_active' => true]);
        
        Run::factory()->create([
            'contest_id' => $contest->id,
            'user_id' => $user->user_id,
            'problem_id' => $problem->id,
            'source_hash' => $hash,
        ]);

        $response = $this->postJson('/api/runs', [
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'source_file' => $file,
        ]);

        $response->assertStatus(422);
    }


    public function test_show_returns_run_details()
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        Sanctum::actingAs($admin);
        $run = Run::factory()->create();
        $response = $this->getJson("/api/runs/{$run->id}");
        $response->assertStatus(200)->assertJsonPath('id', $run->id);
    }

    public function test_show_unauthorized()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $run = Run::factory()->create(['user_id' => $otherUser->user_id]);
        Sanctum::actingAs($user);
        $response = $this->getJson("/api/runs/{$run->id}");
        $response->assertStatus(403);
    }


    public function test_download_source_unauthorized()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $run = Run::factory()->create(['user_id' => $otherUser->user_id]);
        Sanctum::actingAs($user);
        Storage::fake('local');
        Storage::disk('local')->put($run->source_file, 'test content');
        $response = $this->get("/api/runs/{$run->id}/source");
        $response->assertStatus(403);
    }

    public function test_download_source_not_found()
    {
        $run = Run::factory()->create(['source_file' => 'not_found.cpp']);
        $admin = User::factory()->create(['user_type' => 'admin']);
        Sanctum::actingAs($admin);
        Storage::fake('local');
        $response = $this->get("/api/runs/{$run->id}/source");
        $response->assertStatus(404);
    }

    public function test_download_source_works_for_admin()
    {
        Storage::fake('local');
        $fileContent = 'int main() { return 0; }';
        $run = Run::factory()->create(['source_file' => 'test.cpp']);
        Storage::disk('local')->put($run->source_file, $fileContent);
        
        $admin = User::factory()->create(['user_type' => 'admin']);
        Sanctum::actingAs($admin);
        $response = $this->get("/api/runs/{$run->id}/source");
        $response->assertStatus(200);
        $this->assertEquals($fileContent, $response->streamedContent());
    }

    public function test_rejudge_run_without_autojudge()
    {
        Queue::fake();
        $problem = Problem::factory()->create(['auto_judge' => false]);
        $run = Run::factory()->create(['status' => 'judged', 'problem_id' => $problem->id]);
        $admin = User::factory()->create(['user_type' => 'admin']);
        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/runs/{$run->id}/rejudge");
        $response->assertStatus(200)->assertJsonPath('run.status', 'pending');
        Queue::assertNotPushed(JudgeRunJob::class);
    }

    public function test_rejudge_run()
    {
        Queue::fake();
        $problem = Problem::factory()->create(['auto_judge' => true]);
        $run = Run::factory()->create(['status' => 'judged', 'problem_id' => $problem->id]);
        $admin = User::factory()->create(['user_type' => 'admin']);
        Sanctum::actingAs($admin);
        $response = $this->postJson("/api/runs/{$run->id}/rejudge");
        $response->assertStatus(200)->assertJsonPath('run.status', 'pending');
        Queue::assertPushed(JudgeRunJob::class);
    }

    public function test_judge_run()
    {
        $run = Run::factory()->create(['status' => 'pending']);
        $answer = Answer::factory()->create();
        $admin = User::factory()->create(['user_type' => 'admin']);
        Sanctum::actingAs($admin);
        $response = $this->putJson("/api/runs/{$run->id}/judge", ['answer_id' => $answer->id]);
        $response->assertStatus(200)->assertJsonPath('status', 'judged');
    }
}