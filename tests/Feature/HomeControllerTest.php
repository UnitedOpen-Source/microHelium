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
        // Create dummy data to be counted
        DB::table('exercises')->insert([
            ['exerciseName' => 'P1', 'difficulty' => 'easy', 'score' => 100, 'expectedOutcome' => 'outcome1'],
            ['exerciseName' => 'P2', 'difficulty' => 'medium', 'score' => 200, 'expectedOutcome' => 'outcome2'],
        ]);
        DB::table('teams')->insert([['teamName' => 'T1'], ['teamName' => 'T2'], ['teamName' => 'T3']]);
        
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
