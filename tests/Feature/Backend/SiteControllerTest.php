<?php

namespace Tests\Feature\Backend;

use App\Models\Contest;
use App\Models\Site;
use App\Models\SiteJudgingRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access_site_management()
    {
        $team = $this->createTestUser(['user_type' => 'team']);

        $response = $this->actingAs($team)->get('/backend/sites');

        $response->assertStatus(403);
    }

    public function test_admin_can_create_a_site()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();

        $response = $this->actingAs($admin)->post('/backend/sites', [
            'contest_id' => $contest->id,
            'name' => 'Laboratorio 2',
            'score_visibility' => 'own_site',
            'max_judge_wait_time' => 600,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('sites', [
            'contest_id' => $contest->id,
            'name' => 'Laboratorio 2',
            'score_visibility' => 'own_site',
            'max_judge_wait_time' => 600,
        ]);
    }

    public function test_admin_can_update_a_site_and_its_judging_routes()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        $siteA = Site::factory()->create(['contest_id' => $contest->id, 'name' => 'Site A']);
        $siteB = Site::factory()->create(['contest_id' => $contest->id, 'name' => 'Site B']);

        $response = $this->actingAs($admin)->put("/backend/sites/{$siteA->id}", [
            'name' => 'Site A (renamed)',
            'score_visibility' => 'all',
            'max_judge_wait_time' => 900,
            'is_active' => '1',
            'judging_routes' => [$siteB->id],
        ]);

        $response->assertRedirect();
        $siteA->refresh();
        $this->assertSame('Site A (renamed)', $siteA->name);
        $this->assertDatabaseHas('site_judging_routes', [
            'host_site_id' => $siteA->id,
            'source_site_id' => $siteB->id,
        ]);
    }

    public function test_updating_a_site_replaces_its_previous_judging_routes()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        $siteA = Site::factory()->create(['contest_id' => $contest->id]);
        $siteB = Site::factory()->create(['contest_id' => $contest->id]);
        $siteC = Site::factory()->create(['contest_id' => $contest->id]);

        SiteJudgingRoute::create(['host_site_id' => $siteA->id, 'source_site_id' => $siteB->id]);

        $this->actingAs($admin)->put("/backend/sites/{$siteA->id}", [
            'name' => $siteA->name,
            'score_visibility' => 'all',
            'max_judge_wait_time' => 900,
            'judging_routes' => [$siteC->id],
        ]);

        $this->assertDatabaseMissing('site_judging_routes', [
            'host_site_id' => $siteA->id,
            'source_site_id' => $siteB->id,
        ]);
        $this->assertDatabaseHas('site_judging_routes', [
            'host_site_id' => $siteA->id,
            'source_site_id' => $siteC->id,
        ]);
    }

    public function test_a_site_cannot_route_to_itself()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);

        $this->actingAs($admin)->put("/backend/sites/{$site->id}", [
            'name' => $site->name,
            'score_visibility' => 'all',
            'max_judge_wait_time' => 900,
            'judging_routes' => [$site->id],
        ]);

        $this->assertDatabaseMissing('site_judging_routes', [
            'host_site_id' => $site->id,
            'source_site_id' => $site->id,
        ]);
    }

    public function test_admin_can_delete_a_site()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);

        $response = $this->actingAs($admin)->delete("/backend/sites/{$site->id}");

        $response->assertRedirect();
        $this->assertSoftDeleted('sites', ['id' => $site->id]);
    }
}
