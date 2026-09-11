<?php

namespace Tests\Unit;

use App\Jobs\JudgeRunJob;
use App\Models\Run;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ShouldBeUnique (issue #45 code review): runs:reconcile-stuck re-dispatching
 * a run whose original job just hasn't been picked up yet (queue backlog,
 * not a lost job) must not let two workers judge the same run concurrently.
 * Testing Laravel's own unique-job lock mechanism end-to-end would mostly
 * re-test the framework; this asserts the job is actually configured for it.
 */
class JudgeRunJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_is_unique_per_run()
    {
        $run = Run::factory()->create();
        $job = new JudgeRunJob($run);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame((string) $run->id, $job->uniqueId());
    }

    public function test_two_runs_have_different_unique_ids()
    {
        $runA = Run::factory()->create();
        $runB = Run::factory()->create();

        $this->assertNotSame((new JudgeRunJob($runA))->uniqueId(), (new JudgeRunJob($runB))->uniqueId());
    }
}
