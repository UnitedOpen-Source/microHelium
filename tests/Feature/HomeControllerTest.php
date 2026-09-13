<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomeControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test the home page loads correctly and displays statistics.
     *
     * @return void
     */
    public function test_home_page_loads_with_correct_data()
    {
        // 1. Arrange
        // Issue #106: this used to seed `exercises` and `teams`, the 2017
        // Helium tables, and assert the dashboard counted them. A contest
        // created through the wizard writes to `problems` and `users` and
        // never touches those, so the assertion was locking in the bug: on
        // a real contest the dashboard showed 0 problems and 0 teams next
        // to a non-zero submission count.
        $contest = \App\Models\Contest::factory()->create(['is_active' => true]);
        $site = \App\Models\Site::factory()->create(['contest_id' => $contest->id]);

        \App\Models\Problem::factory()->count(2)->create(['contest_id' => $contest->id]);
        \Helium\User::factory()->count(3)->create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'user_type' => 'team',
        ]);
        
        // 2. Act
        $response = $this->get('/');

        // 3. Assert
        $response->assertStatus(200);
        $response->assertViewIs('home');
        
        // Assert that the correct data is passed to the view
        $response->assertViewHas('totalProblems', 2);
        $response->assertViewHas('totalTeams', 3);
        $response->assertViewHas('totalSubmissions'); // Just check for existence
        $response->assertViewHas('acceptedSubmissions');
        $response->assertViewHas('recentSubmissions');
    }

    /**
     * Test that the /home route is protected by auth middleware.
     *
     * @return void
     */
    public function test_named_home_route_requires_authentication()
    {
        $response = $this->get('/home');

        $response->assertRedirect('/login');
    }
}
