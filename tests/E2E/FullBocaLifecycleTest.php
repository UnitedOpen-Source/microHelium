<?php

namespace Tests\E2E;

use App\Jobs\JudgeRunJob;
use App\Models\Leaderboard;
use App\Models\ProblemBank;
use App\Models\Run;
use App\Models\Score;
use App\Models\Task;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The single most important regression net in this suite: it exercises the
 * real chain an actual contest depends on, start to finish, with NOTHING
 * mocked in the judging step (JudgeRunJob runs synchronously via
 * Bus::dispatchSync and AutoJudgeService really invokes gcc/g++/python3
 * inside the container -- the same thing verified by hand in a browser
 * while building #22/#27/#28). Every other test in this suite checks one
 * layer in isolation; this one proves the layers actually connect:
 *
 *   admin wizard -> real Contest/Site/Language/Problem/TestCase rows
 *     -> team submits real source code through the web form
 *     -> AutoJudgeService actually compiles and runs it
 *     -> Score/Leaderboard update
 *     -> scoreboard reflects it
 *     -> a judge can override a verdict by hand
 *     -> a staff member can complete a task
 *
 * If any future change breaks the connection between any two of these
 * pieces (e.g. a column rename, a changed placeholder convention, a
 * middleware that starts blocking one of these routes), this test fails
 * even though each piece's own unit/feature tests might still pass.
 */
class FullBocaLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_contest_lifecycle_from_wizard_to_scoreboard()
    {
        // --- Admin creates the contest through the real wizard ---------
        $admin = $this->createAdminUser();

        $problem = ProblemBank::create([
            'code' => 'ABPLUS',
            'name' => 'A Plus B',
            'description' => 'Some dois inteiros.',
            'input_description' => 'Dois inteiros A e B.',
            'output_description' => 'A soma A + B.',
            'sample_input' => "3 5\n",
            'sample_output' => "8\n",
            'time_limit' => 5,
            'memory_limit' => 256,
            'difficulty' => 'easy',
            'is_active' => true,
        ]);

        $wizardResponse = $this->actingAs($admin)->post('/backend/contest-wizard', [
            'name' => 'Full Lifecycle Contest',
            'description' => 'Created by FullBocaLifecycleTest',
            'start_time' => now()->subMinutes(5)->format('Y-m-d H:i:s'),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'languages' => ['c_gcc13'],
            'problems' => [$problem->id],
        ]);

        $wizardResponse->assertRedirect(route('backend.configurations'));

        $contest = \App\Models\Contest::where('name', 'Full Lifecycle Contest')->firstOrFail();
        $site = \App\Models\Site::where('contest_id', $contest->id)->firstOrFail();
        $language = \App\Models\Language::where('contest_id', $contest->id)->where('extension', 'c_gcc13')->firstOrFail();
        $realProblem = \App\Models\Problem::where('contest_id', $contest->id)->firstOrFail();

        $this->assertTrue($language->is_active, 'the language selected in the wizard form must end up active');
        $this->assertCount(1, $realProblem->testCases, 'the wizard must seed at least the problem bank sample as a TestCase, or nothing is ever judgeable');

        // --- A team registers into that contest and submits real code --
        $team = User::create([
            'fullname' => 'Lifecycle Team',
            'username' => 'lifecycle_team',
            'email' => 'lifecycle-team@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'team',
            'is_enabled' => true,
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ]);

        \Illuminate\Support\Facades\Bus::fake([JudgeRunJob::class]);

        $sourceCode = "#include <stdio.h>\nint main(){int a,b;scanf(\"%d %d\",&a,&b);printf(\"%d\\n\",a+b);return 0;}\n";
        $file = UploadedFile::fake()->createWithContent('solution.c', $sourceCode);

        $submitResponse = $this->actingAs($team)->post("/submit/{$realProblem->id}", [
            'language_id' => $language->id,
            'source_file' => $file,
        ]);

        $submitResponse->assertRedirect(route('submissions'));

        $run = Run::where('contest_id', $contest->id)->where('user_id', $team->user_id)->firstOrFail();
        $this->assertSame('pending', $run->status);

        \Illuminate\Support\Facades\Bus::assertDispatched(JudgeRunJob::class, fn ($job) => $job->run->is($run));

        // --- The auto-judge pipeline actually runs (no mocking) --------
        $judgeService = app(\App\Services\AutoJudgeService::class);
        $judgeService->judge($run->fresh());

        $run->refresh();
        $this->assertSame('judged', $run->status);
        $this->assertNotNull($run->answer_id, 'a real gcc compile + run + diff must produce a verdict');
        $this->assertTrue($run->answer->is_accepted, "expected AC for a correct A+B solution, got '{$run->answer?->short_name}' -- stderr: {$run->auto_judge_stderr}");

        // --- Score/Leaderboard/scoreboard reflect the accepted run -----
        $this->assertDatabaseHas('scores', [
            'contest_id' => $contest->id,
            'user_id' => $team->user_id,
            'problem_id' => $realProblem->id,
            'is_solved' => true,
        ]);

        $leaderboardEntry = Leaderboard::where('contest_id', $contest->id)->where('user_id', $team->user_id)->first();
        $this->assertNotNull($leaderboardEntry);
        $this->assertSame(1, $leaderboardEntry->problems_solved);

        $scoreboardResponse = $this->actingAs($team)->get('/scoreboard');
        $scoreboardResponse->assertStatus(200);
        $scoreboardResponse->assertSeeText('Lifecycle Team');

        // --- A judge can see and manually override a verdict -----------
        $judge = $this->createTestUser(['user_type' => 'judge', 'contest_id' => $contest->id]);
        $wrongAnswer = \App\Models\Answer::where('contest_id', $contest->id)->where('short_name', 'WA')->firstOrFail();

        $judgeIndexResponse = $this->actingAs($judge)->get('/judge/runs');
        $judgeIndexResponse->assertStatus(200);
        $judgeIndexResponse->assertSeeText('Lifecycle Team');

        // Flip the already-judged run back to pending to exercise the manual
        // judge action itself (judge() intentionally refuses to re-judge an
        // already-judged run -- see JudgeControllerTest).
        $run->update(['status' => 'pending']);

        $manualJudgeResponse = $this->actingAs($judge)->post("/judge/runs/{$run->id}", [
            'answer_id' => $wrongAnswer->id,
        ]);
        $manualJudgeResponse->assertRedirect(route('judge.runs'));
        $this->assertDatabaseHas('runs', ['id' => $run->id, 'answer_id' => $wrongAnswer->id, 'judge_id' => $judge->user_id]);

        // --- A staff member can complete a task -------------------------
        $staff = $this->createTestUser(['user_type' => 'staff', 'contest_id' => $contest->id]);
        $task = Task::create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'user_id' => $team->user_id,
            'task_number' => 1,
            'description' => 'Entregar balao do problema A',
            'contest_time' => 100,
            'status' => 'pending',
        ]);

        $staffTasksResponse = $this->actingAs($staff)->get('/staff/tasks');
        $staffTasksResponse->assertStatus(200);

        $completeResponse = $this->actingAs($staff)->post("/staff/tasks/{$task->id}/complete");
        $completeResponse->assertRedirect(route('staff.tasks'));
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'done', 'staff_id' => $staff->user_id]);
    }

    public function test_full_contest_lifecycle_rejects_a_wrong_answer_for_real()
    {
        $admin = $this->createAdminUser();

        $problem = ProblemBank::create([
            'code' => 'ABMINUS',
            'name' => 'A Plus B (for wrong-answer check)',
            'description' => 'Some dois inteiros.',
            'input_description' => 'Dois inteiros A e B.',
            'output_description' => 'A soma A + B.',
            'sample_input' => "3 5\n",
            'sample_output' => "8\n",
            'time_limit' => 5,
            'memory_limit' => 256,
            'difficulty' => 'easy',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post('/backend/contest-wizard', [
            'name' => 'Wrong Answer Contest',
            'start_time' => now()->subMinutes(5)->format('Y-m-d H:i:s'),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'languages' => ['c_gcc13'],
            'problems' => [$problem->id],
        ])->assertRedirect();

        $contest = \App\Models\Contest::where('name', 'Wrong Answer Contest')->firstOrFail();
        $site = \App\Models\Site::where('contest_id', $contest->id)->firstOrFail();
        $language = \App\Models\Language::where('contest_id', $contest->id)->where('extension', 'c_gcc13')->firstOrFail();
        $realProblem = \App\Models\Problem::where('contest_id', $contest->id)->firstOrFail();

        $team = User::create([
            'fullname' => 'Wrong Answer Team',
            'username' => 'wa_team',
            'email' => 'wa-team@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'team',
            'is_enabled' => true,
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ]);

        \Illuminate\Support\Facades\Bus::fake([JudgeRunJob::class]);

        // Subtracts instead of adding -- must come back WA, not AC.
        $sourceCode = "#include <stdio.h>\nint main(){int a,b;scanf(\"%d %d\",&a,&b);printf(\"%d\\n\",a-b);return 0;}\n";
        $file = UploadedFile::fake()->createWithContent('solution.c', $sourceCode);

        $this->actingAs($team)->post("/submit/{$realProblem->id}", [
            'language_id' => $language->id,
            'source_file' => $file,
        ])->assertRedirect(route('submissions'));

        $run = Run::where('contest_id', $contest->id)->where('user_id', $team->user_id)->firstOrFail();

        app(\App\Services\AutoJudgeService::class)->judge($run->fresh());

        $run->refresh();
        $this->assertSame('judged', $run->status);
        $this->assertFalse($run->answer->is_accepted);
        $this->assertSame('WA', $run->answer->short_name);

        $this->assertDatabaseMissing('scores', [
            'contest_id' => $contest->id,
            'user_id' => $team->user_id,
            'problem_id' => $realProblem->id,
            'is_solved' => true,
        ]);
    }
}
