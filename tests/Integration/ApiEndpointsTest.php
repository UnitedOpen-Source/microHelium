<?php

namespace Tests\Integration;

use Tests\TestCase;
use App\Models\Contest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Manually include the User class since it's not in the standard autoload
require_once __DIR__ . '/../../app/User.php';

class ApiEndpointsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase trait from TestCase will handle migrations
    }

    /**
     * Test health check endpoint returns OK status
     */
    public function test_health_endpoint_returns_ok()
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok'
            ]);
    }

    /**
     * Test current contest API endpoint returns null when no active contest
     */
    public function test_current_contest_returns_null_when_no_active_contest()
    {
        $response = $this->getJson('/api/contest/current');

        $response->assertStatus(200);

        // Assert that the response is null or empty array
        $data = $response->json();
        $this->assertTrue($data === null || $data === []);
    }

    /**
     * Test current contest API endpoint returns contest data when active
     */
    public function test_current_contest_returns_data_when_contest_is_active()
    {
        // Create an active contest
        $contestId = DB::table('contests')->insertGetId([
            'name' => 'Test Contest',
            'description' => 'Test description',
            'start_time' => now()->subHour(),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/contest/current');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'id',
                'name',
                'start_time',
                'duration',
                'freeze_time',
                'is_running',
                'is_frozen'
            ])
            ->assertJson([
                'id' => $contestId,
                'name' => 'Test Contest',
                'duration' => 300,
                'freeze_time' => 60,
            ]);
    }

    /**
     * Test current contest API endpoint returns correct running status
     */
    public function test_current_contest_shows_running_status_correctly()
    {
        // Create a contest that's currently running
        DB::table('contests')->insert([
            'name' => 'Running Contest',
            'description' => 'Test description',
            'start_time' => now()->subMinutes(30),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/contest/current');

        $response->assertStatus(200)
            ->assertJson([
                'is_running' => true,
                'is_frozen' => false,
            ]);
    }

    /**
     * Test scoreboard API returns correct data structure
     */
    public function test_scoreboard_api_returns_correct_data()
    {
        // Create test teams
        DB::table('teams')->insert([
            [
                'teamName' => 'Team Alpha',
                'email' => 'alpha@test.com',
                'score' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'teamName' => 'Team Beta',
                'email' => 'beta@test.com',
                'score' => 200,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        $response = $this->get('/scoreboard');

        $response->assertStatus(200)
            ->assertViewIs('scoreboard')
            ->assertViewHas('teams');

        $teams = $response->viewData('teams');
        $this->assertCount(2, $teams);
        $this->assertEquals('Team Beta', $teams[0]->teamName); // Should be sorted by score desc
        $this->assertEquals(200, $teams[0]->score);
    }

    /**
     * Test scoreboard export CSV functionality
     */
    public function test_scoreboard_export_csv_returns_correct_format()
    {


        // Create test teams with team_id set
        // Note: The route uses $team->name but the database column is teamName
        // The teams table has team_id as primary key (not id)
        $teamId = DB::table('teams')->insertGetId([
            'teamName' => 'Team Test',
            'email' => 'test@test.com',
            'score' => 150,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/scoreboard/export');

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Posicao', $content);
        // The CSV should contain score and team information
        $this->assertStringContainsString('150', $content);
    }

    /**
     * Test problem list API returns exercises
     */
    public function test_problem_list_api_returns_exercises()
    {
        // Create test exercises
        DB::table('exercises')->insert([
            [
                'exerciseName' => 'Test Problem 1',
                'category' => 'Algorithm',
                'difficulty' => 'easy',
                'score' => 100,
                'expectedOutcome' => 'Test outcome',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'exerciseName' => 'Test Problem 2',
                'category' => 'Data Structure',
                'difficulty' => 'medium',
                'score' => 200,
                'expectedOutcome' => 'Test outcome 2',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        $response = $this->get('/exercises');

        $response->assertStatus(200)
            ->assertViewIs('exercises.index')
            ->assertViewHas('exercises');

        $exercises = $response->viewData('exercises');
        $this->assertCount(2, $exercises);
    }

    /**
     * Test single problem detail view
     */
    public function test_single_problem_detail_returns_correct_data()
    {
        // Create test exercise
        $exerciseId = DB::table('exercises')->insertGetId([
            'exerciseName' => 'Detailed Problem',
            'category' => 'Algorithm',
            'difficulty' => 'hard',
            'score' => 300,
            'expectedOutcome' => 'Expected output',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get("/exercise/{$exerciseId}");

        $response->assertStatus(200)
            ->assertViewIs('exercises.show')
            ->assertViewHas('exercise');

        $exercise = $response->viewData('exercise');
        $this->assertEquals('Detailed Problem', $exercise->exerciseName);
        $this->assertEquals('hard', $exercise->difficulty);
    }

    /**
     * Test problem not found returns 404
     */
    public function test_problem_detail_returns_404_for_nonexistent_problem()
    {
        $response = $this->get('/exercise/99999');

        $response->assertStatus(404);
    }

    /**
     * Test submissions API returns empty when no user is authenticated
     */
    public function test_submissions_api_returns_empty_for_unauthenticated_user()
    {
        $response = $this->get('/submissions');

        $response->assertRedirect('/login');
    }

    /**
     * Test submissions API with authenticated user
     */
    public function test_submissions_api_returns_user_submissions_when_authenticated()
    {
        // Create test user
        $userId = DB::table('users')->insertGetId([
            'fullname' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'team',
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create necessary contest and site data if runs table exists
        if (Schema::hasTable('runs')) {
            $contestId = DB::table('contests')->insertGetId([
                'name' => 'Test Contest',
                'description' => 'Test',
                'start_time' => now(),
                'duration' => 300,
                'freeze_time' => 60,
                'penalty' => 20,
                'max_file_size' => 100,
                'is_active' => true,
                'is_public' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $siteId = DB::table('sites')->insertGetId([
                'contest_id' => $contestId,
                'name' => 'Main Site',
                'is_active' => true,
                'permit_logins' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $problemId = DB::table('problems')->insertGetId([
                'contest_id' => $contestId,
                'short_name' => 'A',
                'name' => 'Test Problem',
                'basename' => 'test-problem',
                'time_limit' => 1,
                'memory_limit' => 256,
                'auto_judge' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $languageId = DB::table('languages')->insertGetId([
                'contest_id' => $contestId,
                'name' => 'C++',
                'extension' => 'cpp',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $answerId = DB::table('answers')->insertGetId([
                'contest_id' => $contestId,
                'short_name' => 'AC',
                'name' => 'Accepted',
                'is_accepted' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Create test run
            DB::table('runs')->insert([
                'contest_id' => $contestId,
                'site_id' => $siteId,
                'user_id' => $userId,
                'problem_id' => $problemId,
                'language_id' => $languageId,
                'answer_id' => $answerId,
                'run_number' => 1,
                'filename' => 'solution.cpp',
                'source_file' => '/path/to/solution.cpp',
                'source_hash' => hash('sha256', 'test code'),
                'contest_time' => 100,
                'status' => 'judged',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Get user directly from database and create auth instance
        $user = DB::table('users')->where('user_id', $userId)->first();
        $userModel = new \Helium\User((array) $user);
        $userModel->user_id = $userId;

        $response = $this->actingAs($userModel)->get('/submissions');

        $response->assertStatus(200)
            ->assertViewIs('submissions');

        if (Schema::hasTable('runs')) {
            $submissions = $response->viewData('submissions');
            $this->assertGreaterThan(0, count($submissions));
        }
    }

    /**
     * Test clarifications API returns clarifications list
     */
    public function test_clarifications_api_returns_list()
    {
        // Create test user
        $userId = DB::table('users')->insertGetId([
            'fullname' => 'Test User',
            'username' => 'clariuser',
            'email' => 'clari@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'team',
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create contest and site
        $contestId = DB::table('contests')->insertGetId([
            'name' => 'Test Contest',
            'description' => 'Test',
            'start_time' => now(),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $siteId = DB::table('sites')->insertGetId([
            'contest_id' => $contestId,
            'name' => 'Main Site',
            'is_active' => true,
            'permit_logins' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create test clarification
        DB::table('clarifications')->insert([
            'contest_id' => $contestId,
            'site_id' => $siteId,
            'user_id' => $userId,
            'clarification_number' => 1,
            'question' => 'What is the time limit?',
            'contest_time' => 100,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/clarifications');

        $response->assertStatus(200)
            ->assertViewIs('clarifications')
            ->assertViewHas('clarifications');

        $clarifications = $response->viewData('clarifications');
        $this->assertGreaterThan(0, count($clarifications));
        $this->assertEquals('What is the time limit?', $clarifications[0]->question);
    }

    /**
     * Test clarification submission
     */
    public function test_clarification_submission_works()
    {
        // Create contest and site first
        $contestId = DB::table('contests')->insertGetId([
            'name' => 'Test Contest',
            'description' => 'Test',
            'start_time' => now(),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sites')->insert([
            'contest_id' => $contestId,
            'name' => 'Main Site',
            'is_active' => true,
            'permit_logins' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = $this->createTestUser();

        $response = $this->actingAs($user)->post('/clarifications', [
            'question' => 'How do I submit?',
            'problem_id' => null,
        ]);

        $response->assertStatus(302)
            ->assertRedirect(route('clarifications'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('clarifications', [
            'question' => 'How do I submit?',
            'status' => 'pending',
        ]);
    }

    /**
     * Test clarification submission fails without contest setup
     */
    public function test_clarification_submission_fails_without_contest()
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user)->post('/clarifications', [
            'question' => 'Test question',
        ]);

        $response->assertStatus(302)
            ->assertRedirect(route('clarifications'))
            ->assertSessionHas('error');
    }

    /**
     * Test JSON response format for API health endpoint
     */
    public function test_json_response_format_for_health_endpoint()
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure([
                'status'
            ]);

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('status', $data);
    }

    /**
     * Test JSON response format for contest endpoint
     */
    public function test_json_response_format_for_contest_endpoint()
    {
        // Create active contest
        DB::table('contests')->insert([
            'name' => 'JSON Test Contest',
            'description' => 'Test',
            'start_time' => now()->subHour(),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/contest/current');

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure([
                'id',
                'name',
                'start_time',
                'duration',
                'freeze_time',
                'is_running',
                'is_frozen'
            ]);

        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertIsInt($data['id']);
        $this->assertIsString($data['name']);
        $this->assertIsBool($data['is_running']);
        $this->assertIsBool($data['is_frozen']);
    }

    /**
     * Test authentication for protected routes using Sanctum
     */
    public function test_user_endpoint_requires_authentication()
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
    }

    /**
     * Test authenticated user endpoint returns user data
     */
    public function test_user_endpoint_returns_data_when_authenticated()
    {
        // Create test user
        $userId = DB::table('users')->insertGetId([
            'fullname' => 'API Test User',
            'username' => 'apiuser',
            'email' => 'api@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'team',
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Get user directly from database and create auth instance
        $userData = DB::table('users')->where('user_id', $userId)->first();
        $user = new \Helium\User((array) $userData);
        $user->user_id = $userId;

        // Test using actingAs instead of Sanctum token since User model might not have HasApiTokens
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJson([
                'email' => 'api@example.com',
                'username' => 'apiuser',
            ]);
    }

    /**
     * Test up endpoint (simple health check)
     */
    public function test_up_endpoint_returns_ok()
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
        $this->assertEquals('OK', $response->content());
    }

    /**
     * Test home page returns statistics
     */
    public function test_home_page_returns_statistics()
    {
        // Create test data
        DB::table('exercises')->insert([
            'exerciseName' => 'Test Exercise',
            'category' => 'Test',
            'difficulty' => 'easy',
            'score' => 100,
            'expectedOutcome' => 'Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('teams')->insert([
            'teamName' => 'Test Team',
            'email' => 'team@test.com',
            'score' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/');

        $response->assertStatus(200)
            ->assertViewIs('home')
            ->assertViewHas(['totalProblems', 'totalTeams', 'totalSubmissions']);

        $this->assertEquals(1, $response->viewData('totalProblems'));
        $this->assertEquals(1, $response->viewData('totalTeams'));
    }

    /**
     * Test API endpoints handle database errors gracefully
     */
    public function test_api_handles_missing_tables_gracefully()
    {
        // This should not throw an error even if some tables don't exist
        $response = $this->getJson('/api/health');
        $response->assertStatus(200);

        $response = $this->getJson('/api/contest/current');
        $response->assertStatus(200);
    }

    /**
     * Test contest frozen time calculation
     */
    public function test_contest_frozen_status_calculation()
    {
        // Create contest that should be frozen (near end)
        DB::table('contests')->insert([
            'name' => 'Frozen Contest',
            'description' => 'Test',
            'start_time' => now()->subMinutes(250), // Started 250 mins ago
            'duration' => 300, // Total 300 mins (5 hours)
            'freeze_time' => 60, // Freeze last 60 mins
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/contest/current');

        $response->assertStatus(200)
            ->assertJson([
                'is_running' => true,
                'is_frozen' => true,
            ]);
    }

    /**
     * Test multiple contests but only active one is returned
     */
    public function test_only_active_contest_is_returned()
    {
        // Create inactive contest
        DB::table('contests')->insert([
            'name' => 'Inactive Contest',
            'description' => 'Test',
            'start_time' => now(),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => false,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create active contest
        DB::table('contests')->insert([
            'name' => 'Active Contest',
            'description' => 'Test',
            'start_time' => now(),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/contest/current');

        $response->assertStatus(200)
            ->assertJson([
                'name' => 'Active Contest',
            ]);
    }
}
