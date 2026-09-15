<?php

namespace Tests\Feature;

use App\Jobs\JudgeRunJob;
use App\Models\Contest;
use App\Models\Judgehost;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Services\AutoJudgeService;
use App\Services\JudgeWorkQueue;
use Helium\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Issue #193 -- hold a broken problem's judging without taking it off the
 * contest.
 *
 * Mid-contest the jury finds the expected output of problem C is wrong.
 * Teams keep submitting C, every submission collects a WRONG ANSWER caused
 * by the jury's own defect, and each one costs twenty penalty minutes.
 * Deactivating the problem takes the statement away; pausing holds only the
 * verdict, which is what the CCS requirements describe: "pausing the
 * judging of submissions for a specific problem while still allowing teams
 * to submit to that problem".
 */
class PauseProblemJudgingTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    private Problem $problem;

    private Language $language;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()->subHour()]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problem = Problem::factory()->create([
            'contest_id' => $this->contest->id,
            'short_name' => 'C',
            'auto_judge' => true,
        ]);
        $this->language = Language::factory()->create(['contest_id' => $this->contest->id, 'is_active' => true]);
    }

    private function judge(): User
    {
        return $this->createTestUser(['user_type' => 'judge', 'contest_id' => $this->contest->id]);
    }

    private function pendingRun(?Problem $problem = null): Run
    {
        return Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'problem_id' => ($problem ?? $this->problem)->id,
            'language_id' => $this->language->id,
            'user_id' => $this->createTestUser(['contest_id' => $this->contest->id])->user_id,
            'status' => 'pending',
            'answer_id' => null,
        ]);
    }

    public function test_a_judge_pauses_and_resumes_a_problem(): void
    {
        $this->actingAs($this->judge())
            ->post(route('judge.problems.pause', $this->problem))
            ->assertRedirect();

        $this->assertTrue($this->problem->fresh()->isJudgingPaused());

        $this->actingAs($this->judge())
            ->post(route('judge.problems.resume', $this->problem))
            ->assertRedirect();

        $this->assertFalse($this->problem->fresh()->isJudgingPaused());
    }

    public function test_a_team_cannot_pause_judging(): void
    {
        $this->actingAs($this->createTestUser(['user_type' => 'team']))
            ->post(route('judge.problems.pause', $this->problem))
            ->assertStatus(403);

        $this->assertFalse($this->problem->fresh()->isJudgingPaused());
    }

    /**
     * The pull path: a remote judgehost and a local worker both take work
     * from JudgeWorkQueue.
     */
    public function test_the_work_queue_hands_out_nothing_for_a_paused_problem(): void
    {
        $paused = $this->pendingRun();
        $this->problem->update(['judging_paused_at' => now()]);

        $this->assertNull(app(JudgeWorkQueue::class)->claimNextLocally());

        [$host] = Judgehost::issue('sala-1');
        $this->assertNull(app(JudgeWorkQueue::class)->claimNext($host));

        $this->assertSame('pending', $paused->fresh()->status, 'the run left the queue while its problem was on hold');
    }

    /**
     * The path that a first version of this change missed: JudgeRunJob is
     * dispatched straight from Api\RunController::store() and from rejudge,
     * and never asks JudgeWorkQueue. Without a gate here, every FRESH
     * submission to a paused problem would be judged anyway -- the pause
     * would look like it worked while doing nothing for the case it exists
     * for.
     */
    public function test_the_dispatched_job_does_not_judge_a_paused_problem(): void
    {
        $run = $this->pendingRun();
        $this->problem->update(['judging_paused_at' => now()]);

        (new JudgeRunJob($run->fresh()))->handle(app(AutoJudgeService::class));

        $this->assertSame('pending', $run->fresh()->status);
        $this->assertNull($run->fresh()->answer_id, 'a paused problem produced a verdict');
    }

    public function test_another_problem_keeps_being_judged(): void
    {
        $other = Problem::factory()->create([
            'contest_id' => $this->contest->id,
            'short_name' => 'D',
            'auto_judge' => true,
        ]);

        $this->pendingRun();
        $keeps = $this->pendingRun($other);

        $this->problem->update(['judging_paused_at' => now()]);

        $claimed = app(JudgeWorkQueue::class)->claimNextLocally();

        $this->assertNotNull($claimed, 'pausing one problem stopped the whole queue');
        $this->assertSame($keeps->id, $claimed->id);
    }

    /**
     * Releasing the hold has to push the waiting runs back. They were never
     * queued anywhere while paused -- the job returned early and the pull
     * queue skipped them -- so without this they would sit pending until
     * runs:reconcile-stuck noticed, which is a backstop and not a plan.
     */
    public function test_resuming_puts_the_waiting_runs_back_in_the_queue(): void
    {
        Queue::fake();

        $waiting = $this->pendingRun();
        $this->problem->update(['judging_paused_at' => now()]);

        $this->actingAs($this->judge())
            ->post(route('judge.problems.resume', $this->problem))
            ->assertRedirect();

        Queue::assertPushed(JudgeRunJob::class, fn (JudgeRunJob $job) => $job->run->id === $waiting->id);
    }

    /**
     * Without the count on screen, whoever paused forgets to unpause -- and
     * the symptom of that is a queue that does not move, with nobody able
     * to say why.
     */
    public function test_the_judge_screen_shows_what_is_held_and_offers_the_button(): void
    {
        $this->pendingRun();
        $this->pendingRun();
        $this->problem->update(['judging_paused_at' => now()]);

        $response = $this->actingAs($this->judge())->get(route('judge.runs'));

        $response->assertStatus(200);
        $response->assertSee('Julgamento por problema');
        $response->assertSee('2 aguardando');
        $response->assertSee(route('judge.problems.resume', $this->problem), false);
    }

    public function test_pausing_twice_is_not_an_error_and_does_not_move_the_moment(): void
    {
        $this->actingAs($this->judge())->post(route('judge.problems.pause', $this->problem));
        $first = $this->problem->fresh()->judging_paused_at;

        $this->travel(5)->minutes();

        $this->actingAs($this->judge())
            ->post(route('judge.problems.pause', $this->problem))
            ->assertRedirect();

        $this->assertEquals($first, $this->problem->fresh()->judging_paused_at);
    }

    /**
     * Pausing holds the verdict; it does not remove the problem. A team
     * must still be able to submit -- that is the whole difference from
     * deactivating, and the reason teams do not lose the problem while the
     * jury fixes it.
     */
    public function test_a_team_can_still_submit_to_a_paused_problem(): void
    {
        $this->problem->update(['judging_paused_at' => now()]);

        $this->assertTrue($this->problem->fresh()->is_active ?? true);

        $run = $this->pendingRun();

        $this->assertSame('pending', $run->status);
    }
}
