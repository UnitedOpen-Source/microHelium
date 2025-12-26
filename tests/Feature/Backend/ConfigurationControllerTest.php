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
        DB::table('hackathons')->insert(['eventName' => 'Test Hackathon', 'description' => 'Test', 'starts_at' => now(), 'ends_at' => now()->addHours(8)]);

        $response = $this->actingAs($admin)->get(route('backend.configurations'));

        $response->assertStatus(200);
        $response->assertViewIs('backend.configurations');
        $response->assertViewHas('hackathons');
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
        $this->assertDatabaseHas('hackathons', ['eventName' => 'New Awesome Hackathon']);
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
