<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Leaderboard;
use App\Models\Site;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-site scoreboard visibility (issue #18): a team logged in at a site
 * configured with score_visibility = 'own_site' only sees their own site's
 * rows. Everyone else (admin/judge/staff/score, or a site set to 'all')
 * keeps the unrestricted view.
 */
class ScoreboardSiteVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_at_an_own_site_only_site_does_not_see_other_sites()
    {
        $contest = Contest::factory()->create(['is_active' => true]);
        $siteA = Site::factory()->create(['contest_id' => $contest->id, 'score_visibility' => 'own_site']);
        $siteB = Site::factory()->create(['contest_id' => $contest->id]);

        $viewer = $this->createTestUser(['user_type' => 'team', 'contest_id' => $contest->id, 'site_id' => $siteA->id]);
        $ownTeam = User::factory()->create(['fullname' => 'Own Site Team', 'contest_id' => $contest->id, 'site_id' => $siteA->id]);
        $otherTeam = User::factory()->create(['fullname' => 'Other Site Team', 'contest_id' => $contest->id, 'site_id' => $siteB->id]);

        Leaderboard::create(['contest_id' => $contest->id, 'user_id' => $ownTeam->user_id, 'problems_solved' => 1, 'total_time' => 10, 'rank' => 1]);
        Leaderboard::create(['contest_id' => $contest->id, 'user_id' => $otherTeam->user_id, 'problems_solved' => 2, 'total_time' => 5, 'rank' => 2]);

        $response = $this->actingAs($viewer)->get('/scoreboard');

        $response->assertSeeText('Own Site Team');
        $response->assertDontSeeText('Other Site Team');
    }

    public function test_team_at_an_all_visibility_site_sees_every_site()
    {
        $contest = Contest::factory()->create(['is_active' => true]);
        $siteA = Site::factory()->create(['contest_id' => $contest->id, 'score_visibility' => 'all']);
        $siteB = Site::factory()->create(['contest_id' => $contest->id]);

        $viewer = $this->createTestUser(['user_type' => 'team', 'contest_id' => $contest->id, 'site_id' => $siteA->id]);
        $otherTeam = User::factory()->create(['fullname' => 'Other Site Team', 'contest_id' => $contest->id, 'site_id' => $siteB->id]);

        Leaderboard::create(['contest_id' => $contest->id, 'user_id' => $otherTeam->user_id, 'problems_solved' => 1, 'total_time' => 10, 'rank' => 1]);

        $response = $this->actingAs($viewer)->get('/scoreboard');

        $response->assertSeeText('Other Site Team');
    }

    public function test_judge_always_sees_every_site_regardless_of_visibility_setting()
    {
        $contest = Contest::factory()->create(['is_active' => true]);
        $siteA = Site::factory()->create(['contest_id' => $contest->id, 'score_visibility' => 'own_site']);
        $siteB = Site::factory()->create(['contest_id' => $contest->id]);

        $judge = $this->createTestUser(['user_type' => 'judge', 'contest_id' => $contest->id, 'site_id' => $siteA->id]);
        $otherTeam = User::factory()->create(['fullname' => 'Other Site Team', 'contest_id' => $contest->id, 'site_id' => $siteB->id]);

        Leaderboard::create(['contest_id' => $contest->id, 'user_id' => $otherTeam->user_id, 'problems_solved' => 1, 'total_time' => 10, 'rank' => 1]);

        $response = $this->actingAs($judge)->get('/scoreboard');

        $response->assertSeeText('Other Site Team');
    }

    public function test_csv_export_respects_own_site_visibility_too()
    {
        $contest = Contest::factory()->create(['is_active' => true]);
        $siteA = Site::factory()->create(['contest_id' => $contest->id, 'score_visibility' => 'own_site']);
        $siteB = Site::factory()->create(['contest_id' => $contest->id]);

        $viewer = $this->createTestUser(['user_type' => 'team', 'contest_id' => $contest->id, 'site_id' => $siteA->id]);
        $otherTeam = User::factory()->create(['fullname' => 'Hidden Export Team', 'contest_id' => $contest->id, 'site_id' => $siteB->id]);

        Leaderboard::create(['contest_id' => $contest->id, 'user_id' => $otherTeam->user_id, 'problems_solved' => 1, 'total_time' => 10, 'rank' => 1]);

        $response = $this->actingAs($viewer)->get('/scoreboard/export');
        $content = $response->streamedContent();

        $this->assertStringNotContainsString('Hidden Export Team', $content);
    }
}
