<?php

namespace Tests\Feature\Backend;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeamControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test admin can view the team list page.
     */
    public function test_admin_can_view_teams_index()
    {
        $admin = $this->createAdminUser();
        DB::table('teams')->insert(['teamName' => 'Test Team']);

        $response = $this->actingAs($admin)->get(route('backend.teams'));

        $response->assertStatus(200);
        $response->assertViewIs('backend.teams');
        $response->assertViewHas('teams');
        $response->assertSee('Test Team');
    }

    /**
     * Test admin can create a new team.
     */
    public function test_admin_can_create_team()
    {
        $admin = $this->createAdminUser();
        $teamData = [
            'teamName' => 'New Awesome Team',
            'email' => 'new@team.com',
            'score' => 100,
        ];

        $response = $this->actingAs($admin)->post(route('backend.teams'), $teamData);

        $response->assertRedirect(route('backend.teams'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('teams', ['teamName' => 'New Awesome Team']);
    }

    /**
     * Test admin can delete a team.
     */
    public function test_admin_can_delete_team()
    {
        $admin = $this->createAdminUser();
        $teamId = DB::table('teams')->insertGetId(['teamName' => 'Team To Delete']);

        $this->assertDatabaseHas('teams', ['team_id' => $teamId]);

        $response = $this->actingAs($admin)->delete(route('backend.teams.destroy', $teamId));

        $response->assertRedirect(route('backend.teams'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('teams', ['team_id' => $teamId]);
    }

    /**
     * Test non-admin cannot access team management.
     */
    public function test_non_admin_cannot_access_team_management()
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user)->get(route('backend.teams'));
        $response->assertStatus(403);

        $response = $this->actingAs($user)->post(route('backend.teams'), []);
        $response->assertStatus(403);

        $response = $this->actingAs($user)->delete(route('backend.teams.destroy', 1));
        $response->assertStatus(403);
    }
}
