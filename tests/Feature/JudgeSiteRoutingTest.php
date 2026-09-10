<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Models\SiteJudgingRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-site judging routing (issue #18): a judge stationed at a site only
 * sees runs from their own site plus whatever other sites were explicitly
 * routed to them via Backend\SiteController -- an empty routing table means
 * "own site only", matching BOCA's sitejudging default.
 */
class JudgeSiteRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_judge_with_no_site_sees_every_sites_runs_unchanged()
    {
        $contest = Contest::factory()->create(['is_active' => true]);
        $judge = $this->createTestUser(['user_type' => 'judge', 'contest_id' => $contest->id]);
        $siteA = Site::factory()->create(['contest_id' => $contest->id]);
        $siteB = Site::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id]);

        $runA = $this->createRun($contest, $siteA, $language, 'Problem A');
        $runB = $this->createRun($contest, $siteB, $language, 'Problem B');

        $response = $this->actingAs($judge)->get('/judge/runs');

        $response->assertSeeText('Problem A');
        $response->assertSeeText('Problem B');
    }

    public function test_judge_with_a_site_only_sees_their_own_sites_runs_by_default()
    {
        $contest = Contest::factory()->create(['is_active' => true]);
        $siteA = Site::factory()->create(['contest_id' => $contest->id]);
        $siteB = Site::factory()->create(['contest_id' => $contest->id]);
        $judge = $this->createTestUser(['user_type' => 'judge', 'contest_id' => $contest->id, 'site_id' => $siteA->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id]);

        $this->createRun($contest, $siteA, $language, 'Own Site Problem');
        $this->createRun($contest, $siteB, $language, 'Other Site Problem');

        $response = $this->actingAs($judge)->get('/judge/runs');

        $response->assertSeeText('Own Site Problem');
        $response->assertDontSeeText('Other Site Problem');
    }

    public function test_judge_sees_runs_from_a_site_explicitly_routed_to_them()
    {
        $contest = Contest::factory()->create(['is_active' => true]);
        $siteA = Site::factory()->create(['contest_id' => $contest->id]);
        $siteB = Site::factory()->create(['contest_id' => $contest->id]);
        $judge = $this->createTestUser(['user_type' => 'judge', 'contest_id' => $contest->id, 'site_id' => $siteA->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id]);

        SiteJudgingRoute::create(['host_site_id' => $siteA->id, 'source_site_id' => $siteB->id]);

        $this->createRun($contest, $siteA, $language, 'Own Site Problem');
        $this->createRun($contest, $siteB, $language, 'Routed Site Problem');

        $response = $this->actingAs($judge)->get('/judge/runs');

        $response->assertSeeText('Own Site Problem');
        $response->assertSeeText('Routed Site Problem');
    }

    public function test_admin_sees_every_sites_runs_regardless_of_routing()
    {
        $contest = Contest::factory()->create(['is_active' => true]);
        $admin = $this->createAdminUser();
        $admin->update(['contest_id' => $contest->id]);
        $siteA = Site::factory()->create(['contest_id' => $contest->id]);
        $siteB = Site::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id]);

        $this->createRun($contest, $siteA, $language, 'Site A Problem');
        $this->createRun($contest, $siteB, $language, 'Site B Problem');

        $response = $this->actingAs($admin)->get('/judge/runs');

        $response->assertSeeText('Site A Problem');
        $response->assertSeeText('Site B Problem');
    }

    public function test_pending_run_older_than_the_sites_wait_limit_is_flagged_overdue()
    {
        $contest = Contest::factory()->create(['is_active' => true]);
        $site = Site::factory()->create(['contest_id' => $contest->id, 'max_judge_wait_time' => 60]);
        $judge = $this->createTestUser(['user_type' => 'judge', 'contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id]);

        $run = $this->createRun($contest, $site, $language, 'Slow Problem');
        // created_at isn't mass-assignable on Run (not in $fillable), so
        // update() would silently no-op here -- forceFill bypasses that.
        $run->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $response = $this->actingAs($judge)->get('/judge/runs');

        $response->assertSeeText('Atrasado');
    }

    private function createRun(Contest $contest, Site $site, Language $language, string $problemName): Run
    {
        $team = $this->createTestUser(['user_type' => 'team', 'contest_id' => $contest->id, 'site_id' => $site->id]);
        $problem = Problem::factory()->create(['contest_id' => $contest->id, 'name' => $problemName]);

        return Run::factory()->create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'status' => 'pending',
        ]);
    }
}
