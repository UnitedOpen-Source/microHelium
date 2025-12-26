<?php

namespace Tests\Feature\Backend;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContestWizardControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test admin can view the contest wizard page.
     */
    public function test_admin_can_view_contest_wizard()
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get(route('backend.contest-wizard'));

        $response->assertStatus(200);
        $response->assertViewIs('backend.contest-wizard');
        $response->assertViewHas('problemBank');
    }

    /**
     * Test admin can create a new contest via the wizard.
     */
    public function test_admin_can_create_contest_via_wizard()
    {
        $admin = $this->createAdminUser();
        
        // Seed the problem bank
        $problemId = DB::table('problem_bank')->insertGetId(['name' => 'P1', 'code' => 'P1', 'difficulty' => 'easy', 'description' => 'd', 'input_description' => 'i', 'output_description' => 'o', 'time_limit' => 1, 'memory_limit' => 128, 'is_active' => true]);

        $contestData = [
            'name' => 'Wizard Contest',
            'description' => 'A great contest from a wizard',
            'start_time' => now()->addDay()->toDateTimeString(),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 1024,
            'is_active' => 'on',
            'is_public' => 'on',
            'languages' => ['cpp_gpp13', 'java21'],
            'problems' => [$problemId],
        ];

        $response = $this->actingAs($admin)->post(route('backend.contest-wizard'), $contestData);

        $response->assertRedirect(route('backend.configurations'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('contests', ['name' => 'Wizard Contest']);
        $this->assertDatabaseHas('problems', ['name' => 'P1']);
        $this->assertDatabaseHas('languages', ['extension' => 'cpp_gpp13', 'is_active' => true]);
        $this->assertDatabaseHas('languages', ['extension' => 'java21', 'is_active' => true]);
        $this->assertDatabaseHas('languages', ['extension' => 'py3', 'is_active' => false]);
    }

    /**
     * Test non-admin cannot access the contest wizard.
     */
    public function test_non_admin_cannot_access_contest_wizard()
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user)->get(route('backend.contest-wizard'));
        $response->assertStatus(403);

        $response = $this->actingAs($user)->post(route('backend.contest-wizard'), []);
        $response->assertStatus(403);
    }
}
