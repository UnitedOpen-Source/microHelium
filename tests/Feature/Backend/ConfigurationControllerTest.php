<?php

namespace Tests\Feature\Backend;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConfigurationControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test admin can view the configurations page.
     */
    public function test_admin_can_view_configurations_index()
    {
        $admin = $this->createAdminUser();

        // Issue #190: the fixture is a plain `contests` row now, with no
        // `hackathons` row anywhere -- which is what every creation path
        // except the old wizard has always produced, and what this screen
        // used to be unable to show.
        \App\Models\Contest::factory()->create(['name' => 'Test Hackathon']);

        $response = $this->actingAs($admin)->get(route('backend.configurations'));

        $response->assertStatus(200);
        $response->assertViewIs('backend.configurations');
        $response->assertViewHas('contests');
        $response->assertSee('Test Hackathon');
    }

    /**
     * Test admin can create a new hackathon/contest.
     */
    public function test_admin_can_create_hackathon()
    {
        $admin = $this->createAdminUser();
        $hackathonData = [
            'eventName' => 'New Awesome Hackathon',
            'description' => 'A great event',
            'languages' => ['php', 'js'],
        ];

        $response = $this->actingAs($admin)->post(route('backend.configurations'), $hackathonData);

        $response->assertRedirect(route('backend.configurations'));
        $response->assertSessionHas('success');
        // Issue #190: no `hackathons` row is written any more. Asserting
        // one existed was asserting the defect -- the duplicate write is
        // what kept two tables in step by auto-increment coincidence.
        $this->assertDatabaseCount('hackathons', 0);
        $this->assertDatabaseHas('contests', ['name' => 'New Awesome Hackathon']);
        $this->assertDatabaseHas('sites', ['name' => 'Main Site']);
        $this->assertDatabaseHas('languages', ['extension' => 'php', 'is_active' => true]);
        $this->assertDatabaseHas('answers', ['short_name' => 'AC']);
    }

    /**
     * Test non-admin cannot access configurations.
     */
    public function test_non_admin_cannot_access_configurations()
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user)->get(route('backend.configurations'));
        $response->assertStatus(403);

        $response = $this->actingAs($user)->post(route('backend.configurations'), []);
        $response->assertStatus(403);
    }
}
