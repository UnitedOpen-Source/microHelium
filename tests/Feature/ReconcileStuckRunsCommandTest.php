<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Issue #45: the judge screen's "Atrasado" badge (added in #40) is purely
 * visual -- this command is what actually acts on a run stuck pending past
 * its site's max_judge_wait_time.
 */
class ReconcileStuckRunsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_run_still_within_the_wait_limit_is_left_alone()
    {
        Bus::fake();
        $run = $this->makeStuckRun(waitLimit: 900, ageInSeconds: 60);

        $this->artisan('runs:reconcile-stuck')->assertExitCode(0);

        Bus::assertNotDispatched(\App\Jobs\JudgeRunJob::class);
        $this->assertSame('pending', $run->fresh()->status);
    }

    public function test_a_stuck_run_is_redispatched_once()
    {
        Bus::fake();
        $run = $this->makeStuckRun(waitLimit: 60, ageInSeconds: 600);

        $this->artisan('runs:reconcile-stuck')->assertExitCode(0);

        Bus::assertDispatched(\App\Jobs\JudgeRunJob::class, fn ($job) => $job->run->is($run));
        $run->refresh();
        $this->assertSame(1, $run->reconcile_attempts);
        $this->assertSame('pending', $run->status);
    }

    public function test_a_run_already_retried_once_and_still_stuck_is_marked_judge_error()
    {
        Bus::fake();
        $run = $this->makeStuckRun(waitLimit: 60, ageInSeconds: 600, reconcileAttempts: 1);

        $this->artisan('runs:reconcile-stuck')->assertExitCode(0);

        Bus::assertNotDispatched(\App\Jobs\JudgeRunJob::class);
        $run->refresh();
        $this->assertSame('judged', $run->status);
        $this->assertSame('CS', $run->answer->short_name);
    }

    /**
     * A worker that dies mid-judge (after AutoJudgeService::judge() already
     * flipped the status to 'judging', before finishing) previously left
     * the run stuck showing the overdue badge forever -- the reconciler
     * only queried status='pending'.
     */
    public function test_a_run_stuck_in_judging_status_is_also_reconciled()
    {
        Bus::fake();
        $run = $this->makeStuckRun(waitLimit: 60, ageInSeconds: 600, status: 'judging');

        $this->artisan('runs:reconcile-stuck')->assertExitCode(0);

        Bus::assertDispatched(\App\Jobs\JudgeRunJob::class, fn ($job) => $job->run->is($run));
        $this->assertSame(1, $run->fresh()->reconcile_attempts);
    }

    public function test_giving_up_sets_judged_time_but_does_not_count_as_a_scored_attempt()
    {
        Bus::fake();
        $run = $this->makeStuckRun(waitLimit: 60, ageInSeconds: 600, reconcileAttempts: 1);

        $this->artisan('runs:reconcile-stuck')->assertExitCode(0);

        $run->refresh();
        $this->assertNotNull($run->judged_time, 'judged_time must be set whenever status becomes judged');
        // The judge-error verdict is our infrastructure's fault, not the
        // team's -- it must not create/increment a Score row for this
        // problem (see AutoJudgeService::handleJudgingError()'s identical
        // precedent).
        $this->assertDatabaseMissing('scores', [
            'contest_id' => $run->contest_id,
            'user_id' => $run->user_id,
            'problem_id' => $run->problem_id,
        ]);
    }

    private function makeStuckRun(int $waitLimit, int $ageInSeconds, int $reconcileAttempts = 0, string $status = 'pending'): Run
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id, 'max_judge_wait_time' => $waitLimit]);
        $team = User::factory()->create(['contest_id' => $contest->id, 'site_id' => $site->id]);
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id]);
        Answer::factory()->create(['contest_id' => $contest->id, 'short_name' => 'CS', 'is_accepted' => false]);

        $run = Run::factory()->create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'status' => $status,
            'reconcile_attempts' => $reconcileAttempts,
        ]);
        $run->forceFill(['created_at' => now()->subSeconds($ageInSeconds)])->save();

        return $run;
    }
}
