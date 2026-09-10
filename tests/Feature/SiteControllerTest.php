<?php

namespace Tests\Feature;

use App\Models\Clarification;
use App\Models\Contest;
use App\Models\Site;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Site coordinator screens (issue #18): everything scoped to the
 * coordinator's own site_id. Mirrors the access-scoping tests already in
 * place for JudgeController/StaffController.
 */
class SiteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_cannot_access_site_coordinator_screens()
    {
        $team = $this->createTestUser(['user_type' => 'team']);

        $this->actingAs($team)->get('/site/dashboard')->assertStatus(403);
        $this->actingAs($team)->get('/site/tasks')->assertStatus(403);
        $this->actingAs($team)->get('/site/teams')->assertStatus(403);
        $this->actingAs($team)->get('/site/clarifications')->assertStatus(403);
    }

    public function test_coordinator_sees_only_their_own_sites_dashboard_data()
    {
        $contest = Contest::factory()->create();
        $siteA = Site::factory()->create(['contest_id' => $contest->id]);
        $siteB = Site::factory()->create(['contest_id' => $contest->id]);
        $coordinator = $this->createTestUser(['user_type' => 'site', 'site_id' => $siteA->id]);
        $this->createTestUser(['user_type' => 'team', 'site_id' => $siteA->id, 'fullname' => 'Own Site Team']);
        $this->createTestUser(['user_type' => 'team', 'site_id' => $siteB->id, 'fullname' => 'Other Site Team']);

        $response = $this->actingAs($coordinator)->get('/site/dashboard');

        $response->assertStatus(200);
        $response->assertSeeText('Own Site Team');
        $response->assertDontSeeText('Other Site Team');
    }

    public function test_coordinator_sees_only_their_own_sites_tasks()
    {
        $contest = Contest::factory()->create();
        $siteA = Site::factory()->create(['contest_id' => $contest->id]);
        $siteB = Site::factory()->create(['contest_id' => $contest->id]);
        $coordinator = $this->createTestUser(['user_type' => 'site', 'site_id' => $siteA->id]);
        Task::factory()->create(['contest_id' => $contest->id, 'site_id' => $siteA->id, 'description' => 'Own Site Task']);
        Task::factory()->create(['contest_id' => $contest->id, 'site_id' => $siteB->id, 'description' => 'Other Site Task']);

        $response = $this->actingAs($coordinator)->get('/site/tasks');

        $response->assertSeeText('Own Site Task');
        $response->assertDontSeeText('Other Site Task');
    }

    public function test_coordinator_cannot_complete_a_task_from_another_site()
    {
        $contest = Contest::factory()->create();
        $siteA = Site::factory()->create(['contest_id' => $contest->id]);
        $siteB = Site::factory()->create(['contest_id' => $contest->id]);
        $coordinator = $this->createTestUser(['user_type' => 'site', 'site_id' => $siteA->id]);
        $task = Task::factory()->create(['contest_id' => $contest->id, 'site_id' => $siteB->id]);

        $response = $this->actingAs($coordinator)->post("/site/tasks/{$task->id}/complete");

        $response->assertStatus(403);
        $this->assertSame('pending', $task->fresh()->status);
    }

    public function test_coordinator_can_create_a_team_scoped_to_their_own_site()
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $coordinator = $this->createTestUser(['user_type' => 'site', 'site_id' => $site->id]);

        $response = $this->actingAs($coordinator)->post('/site/teams', [
            'fullname' => 'New Team',
            'username' => 'newteam',
            'email' => 'newteam@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('site.teams'));
        $this->assertDatabaseHas('users', [
            'username' => 'newteam',
            'user_type' => 'team',
            'site_id' => $site->id,
            'contest_id' => $contest->id,
        ]);
    }

    public function test_coordinator_sees_only_their_own_sites_clarifications()
    {
        $contest = Contest::factory()->create();
        $siteA = Site::factory()->create(['contest_id' => $contest->id]);
        $siteB = Site::factory()->create(['contest_id' => $contest->id]);
        $coordinator = $this->createTestUser(['user_type' => 'site', 'site_id' => $siteA->id]);
        Clarification::factory()->create(['contest_id' => $contest->id, 'site_id' => $siteA->id, 'question' => 'Own Site Question']);
        Clarification::factory()->create(['contest_id' => $contest->id, 'site_id' => $siteB->id, 'question' => 'Other Site Question']);

        $response = $this->actingAs($coordinator)->get('/site/clarifications');

        $response->assertSeeText('Own Site Question');
        $response->assertDontSeeText('Other Site Question');
    }

    public function test_coordinator_can_answer_their_own_sites_clarification()
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $coordinator = $this->createTestUser(['user_type' => 'site', 'site_id' => $site->id]);
        $clarification = Clarification::factory()->create(['contest_id' => $contest->id, 'site_id' => $site->id]);

        $response = $this->actingAs($coordinator)->post("/site/clarifications/{$clarification->id}/answer", [
            'answer' => 'Sim, use inteiros.',
        ]);

        $response->assertRedirect(route('site.clarifications'));
        $this->assertSame('answered', $clarification->fresh()->status);
        $this->assertSame('Sim, use inteiros.', $clarification->fresh()->answer);
    }

    public function test_coordinator_cannot_answer_another_sites_clarification()
    {
        $contest = Contest::factory()->create();
        $siteA = Site::factory()->create(['contest_id' => $contest->id]);
        $siteB = Site::factory()->create(['contest_id' => $contest->id]);
        $coordinator = $this->createTestUser(['user_type' => 'site', 'site_id' => $siteA->id]);
        $clarification = Clarification::factory()->create(['contest_id' => $contest->id, 'site_id' => $siteB->id]);

        $response = $this->actingAs($coordinator)->post("/site/clarifications/{$clarification->id}/answer", [
            'answer' => 'Nao deveria ver isso.',
        ]);

        $response->assertStatus(403);
        $this->assertSame('pending', $clarification->fresh()->status);
    }

    public function test_admin_with_no_site_can_spot_check_a_site_via_query_param()
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $admin = $this->createAdminUser();
        $this->createTestUser(['user_type' => 'team', 'site_id' => $site->id, 'fullname' => 'Spot Checked Team']);

        $response = $this->actingAs($admin)->get('/site/dashboard?site_id=' . $site->id);

        $response->assertSeeText('Spot Checked Team');
    }

    public function test_coordinator_cannot_view_another_site_via_query_param()
    {
        $contest = Contest::factory()->create();
        $siteA = Site::factory()->create(['contest_id' => $contest->id]);
        $siteB = Site::factory()->create(['contest_id' => $contest->id]);
        $coordinator = $this->createTestUser(['user_type' => 'site', 'site_id' => $siteA->id]);
        $this->createTestUser(['user_type' => 'team', 'site_id' => $siteB->id, 'fullname' => 'Other Site Team']);

        $response = $this->actingAs($coordinator)->get('/site/dashboard?site_id=' . $siteB->id);

        $response->assertDontSeeText('Other Site Team');
    }
}
