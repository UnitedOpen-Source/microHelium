<?php

namespace Tests\Feature\FrontendApi;

use App\Console\Commands\ReconcileStuckRunsCommand;
use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use Helium\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Issue #191 -- the screen that was in the menu with nothing behind it.
 *
 * `/judge/health` answered 200 while `/api/frontend/judging-health`
 * answered 404, so the page rendered "this resource is not available yet".
 * It was the only one of sixteen feature endpoints that did not resolve.
 */
class JudgingHealthTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    private Problem $problem;

    private Language $language;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create([
            'is_active' => true,
            'is_practice' => false,
            'start_time' => now()->subHour(),
            'duration' => 300,
        ]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id, 'max_judge_wait_time' => 900]);
        $this->problem = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'A']);
        $this->language = Language::factory()->create(['contest_id' => $this->contest->id]);
    }

    private function makeRun(array $attributes = []): Run
    {
        return Run::factory()->create(array_merge([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'problem_id' => $this->problem->id,
            'language_id' => $this->language->id,
            'user_id' => $this->createTestUser(['contest_id' => $this->contest->id])->user_id,
            'status' => 'pending',
            'answer_id' => null,
            'reconcile_attempts' => 0,
        ], $attributes));
    }

    private function admin(): User
    {
        return $this->createTestUser(['user_type' => 'admin', 'contest_id' => $this->contest->id]);
    }

    public function test_the_endpoint_the_page_calls_now_answers(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/frontend/judging-health')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['generated_at', 'watchdog', 'summary', 'items', 'meta']]);
    }

    public function test_a_team_is_refused(): void
    {
        $this->actingAs($this->createTestUser(['user_type' => 'team']))
            ->getJson('/api/frontend/judging-health')
            ->assertStatus(403);
    }

    /**
     * The four recovery states, each against a run that is really in it.
     * They are independent of the verdict: "recovered" means finished after
     * a retry, not necessarily accepted.
     */
    public function test_each_run_reports_the_recovery_state_it_is_actually_in(): void
    {
        $accepted = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'AC', 'is_accepted' => true]);
        $systemError = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'CS', 'is_accepted' => false]);

        $overdue = $this->makeRun(['created_at' => now()->subHour()]);
        $retrying = $this->makeRun(['created_at' => now()->subHour(), 'reconcile_attempts' => 1]);
        $recovered = $this->makeRun(['status' => 'judged', 'answer_id' => $accepted->id, 'reconcile_attempts' => 1]);
        $failed = $this->makeRun(['status' => 'judged', 'answer_id' => $systemError->id, 'reconcile_attempts' => 1]);
        // Judged normally: not part of the recovery story, and listing it
        // would bury the handful that are.
        $normal = $this->makeRun(['status' => 'judged', 'answer_id' => $accepted->id, 'reconcile_attempts' => 0]);

        $items = collect(
            $this->actingAs($this->admin())->getJson('/api/frontend/judging-health')->json('data.items')
        )->keyBy('id');

        $this->assertSame('overdue', $items[$overdue->id]['recovery_status']);
        $this->assertSame('retrying', $items[$retrying->id]['recovery_status']);
        $this->assertSame('recovered', $items[$recovered->id]['recovery_status']);
        $this->assertSame('failed', $items[$failed->id]['recovery_status']);
        $this->assertArrayNotHasKey($normal->id, $items->all(), 'a run judged normally was listed as a recovery case');
    }

    /**
     * The spec is explicit that summary covers everything in the horizon
     * "independentemente do filtro status". A summary that shrinks with the
     * filter would tell an operator the problem went away when they were
     * only looking at one slice of it.
     */
    public function test_the_summary_ignores_the_status_filter(): void
    {
        $this->makeRun(['created_at' => now()->subHour()]);
        $this->makeRun(['created_at' => now()->subHour(), 'reconcile_attempts' => 1]);

        $filtered = $this->actingAs($this->admin())
            ->getJson('/api/frontend/judging-health?status=overdue')
            ->json('data');

        $this->assertCount(1, $filtered['items'], 'the filter did not narrow the list');
        $this->assertSame(1, $filtered['summary']['overdue']);
        $this->assertSame(1, $filtered['summary']['retrying'], 'the summary shrank with the filter');
    }

    /**
     * The failure nobody notices: a stopped scheduler looks exactly like a
     * contest where nothing is stuck. Both show an empty list.
     */
    public function test_it_says_whether_the_watchdog_is_actually_running(): void
    {
        Cache::forget(ReconcileStuckRunsCommand::LAST_RUN_KEY);

        $never = $this->actingAs($this->admin())->getJson('/api/frontend/judging-health')->json('data.watchdog');
        $this->assertFalse($never['enabled'], 'a watchdog that has never run was reported as running');
        $this->assertNull($never['last_checked_at']);

        $this->artisan('runs:reconcile-stuck')->assertExitCode(0);

        $after = $this->actingAs($this->admin())->getJson('/api/frontend/judging-health')->json('data.watchdog');
        $this->assertTrue($after['enabled']);
        $this->assertNotNull($after['last_checked_at']);

        // Stale is not the same as never: the operator needs to see when it
        // was last alive, not just that it is not alive now.
        Cache::forever(ReconcileStuckRunsCommand::LAST_RUN_KEY, now()->subHour()->toISOString());

        $stale = $this->actingAs($this->admin())->getJson('/api/frontend/judging-health')->json('data.watchdog');
        $this->assertFalse($stale['enabled']);
        $this->assertNotNull($stale['last_checked_at']);
    }

    /**
     * A judge stationed at a site sees their own site plus whatever was
     * routed to them (#41) -- the same rule as JudgeController::index().
     */
    public function test_a_site_judge_sees_only_their_own_sites_runs(): void
    {
        $otherSite = Site::factory()->create(['contest_id' => $this->contest->id, 'max_judge_wait_time' => 900]);

        $mine = $this->makeRun(['created_at' => now()->subHour()]);
        $theirs = $this->makeRun(['site_id' => $otherSite->id, 'created_at' => now()->subHour()]);

        $judge = $this->createTestUser([
            'user_type' => 'judge',
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
        ]);

        $ids = collect($this->actingAs($judge)->getJson('/api/frontend/judging-health')->json('data.items'))
            ->pluck('id');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids, 'a site judge was shown another site\'s runs');
    }

    /**
     * The spec forbids inventing activity: Run carries no activity
     * timestamp, so these are null rather than derived from created_at.
     * A screen that confidently shows a wrong "last seen" is worse than one
     * that says it does not know.
     */
    public function test_it_reports_null_rather_than_inventing_activity_it_cannot_know(): void
    {
        $this->makeRun(['created_at' => now()->subHour()]);

        $item = $this->actingAs($this->admin())->getJson('/api/frontend/judging-health')->json('data.items.0');

        $this->assertNull($item['last_activity_at']);
        $this->assertNull($item['next_check_at']);
        $this->assertSame(900, $item['max_wait_seconds']);
    }
}
