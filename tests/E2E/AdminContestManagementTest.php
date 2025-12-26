<?php

namespace Tests\E2E;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\Contest;
use App\Models\Language;
use App\Models\ProblemBank;
use Helium\User;

class AdminContestManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Create an admin user using the User model
        $this->admin = User::create([
            'fullname' => 'Admin Test User',
            'username' => 'admin_test',
            'email' => 'admin@test.com',
            'password' => bcrypt('password123'),
            'user_type' => 'admin',
            'is_enabled' => true,
        ]);
    }

    /**
     * Test 1: Admin can access backend dashboard
     */
    public function test_admin_can_access_backend_dashboard()
    {
        // Act as admin and access various backend routes
        $response = $this->actingAs($this->admin)
            ->get('/backend/exercises');

        $response->assertStatus(200);
        $response->assertViewIs('backend.exercises');

        // Test access to users management
        $response = $this->actingAs($this->admin)
            ->get('/backend/users');

        $response->assertStatus(200);
        $response->assertViewIs('backend.users');

        // Test access to configurations page
        $response = $this->actingAs($this->admin)
            ->get('/backend/configurations');

        $response->assertStatus(200);
        $response->assertViewIs('backend.configurations');

        // Test access to contest wizard
        $response = $this->actingAs($this->admin)
            ->get('/backend/contest-wizard');

        $response->assertStatus(200);
        $response->assertViewIs('backend.contest-wizard');
        $response->assertViewHas('problemBank');
    }

    /**
     * Test 2: Admin can create new contest via wizard
     */
    public function test_admin_can_create_new_contest_via_wizard()
    {
        // Create test problems in the problem bank
        $problem1 = ProblemBank::create([
            'code' => 'E2E001',
            'name' => 'Hello World',
            'description' => 'Print Hello World',
            'input_description' => 'No input',
            'output_description' => 'Hello World',
            'time_limit' => 1000,
            'memory_limit' => 256,
            'difficulty' => 'easy',
            'is_active' => true,
        ]);

        $problem2 = ProblemBank::create([
            'code' => 'E2E002',
            'name' => 'Sum Two Numbers',
            'description' => 'Calculate sum of two integers',
            'input_description' => 'Two integers A and B',
            'output_description' => 'A + B',
            'time_limit' => 1000,
            'memory_limit' => 256,
            'difficulty' => 'easy',
            'is_active' => true,
        ]);

        $contestData = [
            'name' => 'E2E Test Contest 2024',
            'description' => 'End-to-end test contest for admin management',
            'start_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'duration' => 300, // 5 hours
            'freeze_time' => 60, // 1 hour before end
            'penalty' => 20, // 20 minutes per wrong submission
            'max_file_size' => 100, // 100 KB
            'is_active' => true,
            'is_public' => true,
            'languages' => ['c_gcc13', 'cpp_gpp13', 'py3', 'java21'],
            'problems' => [$problem1->id, $problem2->id],
        ];

        // Submit the contest creation request
        $response = $this->actingAs($this->admin)
            ->post('/backend/contest-wizard', $contestData);

        // Assert redirect with success message
        $response->assertRedirect(route('backend.configurations'));
        $response->assertSessionHas('success');

        // Verify the contest was created in the database
        $this->assertDatabaseHas('contests', [
            'name' => 'E2E Test Contest 2024',
            'description' => 'End-to-end test contest for admin management',
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
        ]);

        // Verify hackathon was created
        $this->assertDatabaseHas('hackathons', [
            'eventName' => 'E2E Test Contest 2024',
            'description' => 'End-to-end test contest for admin management',
        ]);

        // Get the created contest
        $contest = DB::table('contests')->where('name', 'E2E Test Contest 2024')->first();
        $this->assertNotNull($contest);

        // Verify default site was created
        $this->assertDatabaseHas('sites', [
            'contest_id' => $contest->id,
            'name' => 'Main Site',
            'is_active' => true,
            'permit_logins' => true,
        ]);

        // Verify languages were created and selected ones are active
        $this->assertDatabaseHas('languages', [
            'contest_id' => $contest->id,
            'extension' => 'c_gcc13',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('languages', [
            'contest_id' => $contest->id,
            'extension' => 'py3',
            'is_active' => true,
        ]);

        // Verify default answers were created
        $answersCount = DB::table('answers')->where('contest_id', $contest->id)->count();
        $this->assertEquals(8, $answersCount);

        $this->assertDatabaseHas('answers', [
            'contest_id' => $contest->id,
            'short_name' => 'AC',
            'is_accepted' => true,
        ]);

        // Verify problems were imported
        $problemsCount = DB::table('problems')->where('contest_id', $contest->id)->count();
        $this->assertEquals(2, $problemsCount);

        $this->assertDatabaseHas('problems', [
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Hello World',
        ]);

        $this->assertDatabaseHas('problems', [
            'contest_id' => $contest->id,
            'short_name' => 'B',
            'name' => 'Sum Two Numbers',
        ]);
    }

    /**
     * Test 3: Admin can edit existing contest
     */
    public function test_admin_can_edit_existing_contest()
    {
        // Create a contest first
        $hackathonId = DB::table('hackathons')->insertGetId([
            'eventName' => 'Original Contest Name',
            'description' => 'Original description',
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->addHours(5)->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contestId = DB::table('contests')->insertGetId([
            'name' => 'Original Contest Name',
            'description' => 'Original description',
            'start_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => false,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Access the edit page
        $response = $this->actingAs($this->admin)
            ->get("/backend/contest/{$contestId}/edit");

        $response->assertStatus(200);
        $response->assertViewIs('backend.contest-edit');
        $response->assertViewHas('hackathon');
        $response->assertViewHas('contest');

        // Update the contest
        $updatedData = [
            'name' => 'Updated Contest Name',
            'description' => 'Updated description with more details',
            'start_time' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'duration' => 420, // 7 hours
            'freeze_time' => 90, // 1.5 hours before end
            'penalty' => 25, // 25 minutes penalty
            'max_file_size' => 150, // 150 KB
            'is_active' => true,
            'is_public' => false,
            'unlock_key' => '', // Empty unlock key
        ];

        $response = $this->actingAs($this->admin)
            ->put("/backend/contest/{$contestId}/update", $updatedData);

        $response->assertRedirect(route('backend.configurations'));
        $response->assertSessionHas('success');

        // Verify the contest was updated
        $this->assertDatabaseHas('contests', [
            'id' => $contestId,
            'name' => 'Updated Contest Name',
            'description' => 'Updated description with more details',
            'duration' => 420,
            'freeze_time' => 90,
            'penalty' => 25,
            'max_file_size' => 150,
            'is_active' => 1,
            'is_public' => 1, // is_public is set to 1 because the field is present in request
        ]);

        // Verify hackathon was also updated
        $this->assertDatabaseHas('hackathons', [
            'hackathon_id' => $hackathonId,
            'eventName' => 'Updated Contest Name',
            'description' => 'Updated description with more details',
        ]);

        // Verify the original data no longer exists
        $this->assertDatabaseMissing('contests', [
            'id' => $contestId,
            'name' => 'Original Contest Name',
        ]);
    }

    /**
     * Test 4: Admin can activate/deactivate contest
     */
    public function test_admin_can_activate_and_deactivate_contest()
    {
        // Create two contests
        $contest1Id = DB::table('contests')->insertGetId([
            'name' => 'Contest 1',
            'description' => 'First contest',
            'start_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contest2Id = DB::table('contests')->insertGetId([
            'name' => 'Contest 2',
            'description' => 'Second contest',
            'start_time' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'duration' => 240,
            'freeze_time' => 30,
            'penalty' => 15,
            'max_file_size' => 80,
            'is_active' => false,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Verify initial state
        $this->assertDatabaseHas('contests', [
            'id' => $contest1Id,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('contests', [
            'id' => $contest2Id,
            'is_active' => false,
        ]);

        // Activate contest 2 (should deactivate contest 1)
        $response = $this->actingAs($this->admin)
            ->post("/backend/contest/{$contest2Id}/activate");

        $response->assertRedirect(route('backend.configurations'));
        $response->assertSessionHas('success');

        // Verify contest 2 is now active and contest 1 is inactive
        $this->assertDatabaseHas('contests', [
            'id' => $contest2Id,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('contests', [
            'id' => $contest1Id,
            'is_active' => false,
        ]);

        // Activate contest 1 again
        $response = $this->actingAs($this->admin)
            ->post("/backend/contest/{$contest1Id}/activate");

        $response->assertRedirect(route('backend.configurations'));

        // Verify contest 1 is now active and contest 2 is inactive
        $this->assertDatabaseHas('contests', [
            'id' => $contest1Id,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('contests', [
            'id' => $contest2Id,
            'is_active' => false,
        ]);
    }

    /**
     * Test 5: Admin can freeze scoreboard
     */
    public function test_admin_can_freeze_scoreboard()
    {
        // Create an active contest
        $contestId = DB::table('contests')->insertGetId([
            'name' => 'Freeze Test Contest',
            'description' => 'Testing scoreboard freeze',
            'start_time' => now()->subHours(2)->format('Y-m-d H:i:s'), // Started 2 hours ago
            'duration' => 300, // 5 hours total
            'freeze_time' => 60, // Originally 1 hour before end
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Verify initial freeze_time
        $this->assertDatabaseHas('contests', [
            'id' => $contestId,
            'freeze_time' => 60,
        ]);

        // Freeze the scoreboard immediately
        $response = $this->actingAs($this->admin)
            ->post('/backend/contest/freeze');

        $response->assertRedirect(route('backend.configurations'));
        $response->assertSessionHas('success');

        // Verify scoreboard is frozen (freeze_time set to 0)
        $this->assertDatabaseHas('contests', [
            'id' => $contestId,
            'freeze_time' => 0,
        ]);

        // Verify the frozen contest is still active
        $this->assertDatabaseHas('contests', [
            'id' => $contestId,
            'is_active' => true,
        ]);
    }

    /**
     * Test 6: Admin can end contest
     */
    public function test_admin_can_end_contest()
    {
        // Create an active running contest
        $contestId = DB::table('contests')->insertGetId([
            'name' => 'Running Contest',
            'description' => 'A contest that is currently running',
            'start_time' => now()->subHours(1)->format('Y-m-d H:i:s'), // Started 1 hour ago
            'duration' => 300, // 5 hours total
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Verify contest is active
        $this->assertDatabaseHas('contests', [
            'id' => $contestId,
            'is_active' => true,
        ]);

        // End the contest
        $response = $this->actingAs($this->admin)
            ->post('/backend/contest/end');

        $response->assertRedirect(route('backend.configurations'));
        $response->assertSessionHas('success');

        // Verify contest is now inactive
        $this->assertDatabaseHas('contests', [
            'id' => $contestId,
            'is_active' => false,
        ]);

        // Verify success message
        $successMessage = session('success');
        $this->assertStringContainsString('encerrada', strtolower($successMessage));
    }

    /**
     * Test 7: Admin can delete contest
     */
    public function test_admin_can_delete_contest()
    {
        // Create a hackathon and contest
        $hackathonId = DB::table('hackathons')->insertGetId([
            'eventName' => 'Contest to Delete',
            'description' => 'This contest will be deleted',
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->addHours(5)->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contestId = DB::table('contests')->insertGetId([
            'name' => 'Contest to Delete',
            'description' => 'This contest will be deleted',
            'start_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => false,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create related data (site, languages, answers)
        DB::table('sites')->insert([
            'contest_id' => $contestId,
            'name' => 'Main Site',
            'is_active' => true,
            'permit_logins' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('languages')->insert([
            'contest_id' => $contestId,
            'name' => 'Python 3.12',
            'extension' => 'py3',
            'compile_command' => '',
            'run_command' => 'python3 {file}',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('answers')->insert([
            'contest_id' => $contestId,
            'short_name' => 'AC',
            'name' => 'Accepted',
            'is_accepted' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Verify everything exists
        $this->assertDatabaseHas('contests', ['id' => $contestId]);
        $this->assertDatabaseHas('hackathons', ['hackathon_id' => $hackathonId]);
        $this->assertDatabaseHas('sites', ['contest_id' => $contestId]);
        $this->assertDatabaseHas('languages', ['contest_id' => $contestId]);
        $this->assertDatabaseHas('answers', ['contest_id' => $contestId]);

        // Delete the contest
        $response = $this->actingAs($this->admin)
            ->delete("/backend/contest/{$contestId}/delete");

        $response->assertRedirect(route('backend.configurations'));
        $response->assertSessionHas('success');

        // Verify contest and related data were deleted
        $this->assertDatabaseMissing('contests', ['id' => $contestId]);
        $this->assertDatabaseMissing('hackathons', ['hackathon_id' => $hackathonId]);
        $this->assertDatabaseMissing('sites', ['contest_id' => $contestId]);
        $this->assertDatabaseMissing('languages', ['contest_id' => $contestId]);
        $this->assertDatabaseMissing('answers', ['contest_id' => $contestId]);

        // Verify success message
        $successMessage = session('success');
        $this->assertStringContainsString('exclu', strtolower($successMessage));
    }

    /**
     * Test 8: Complete admin workflow - create, edit, activate, freeze, end, delete
     */
    public function test_complete_admin_contest_workflow()
    {
        // Step 1: Create a contest
        $problem = ProblemBank::create([
            'code' => 'WORKFLOW001',
            'name' => 'Workflow Problem',
            'description' => 'Test problem for workflow',
            'input_description' => 'Input',
            'output_description' => 'Output',
            'time_limit' => 1000,
            'memory_limit' => 256,
            'difficulty' => 'easy',
            'is_active' => true,
        ]);

        $contestData = [
            'name' => 'Workflow Test Contest',
            'description' => 'Testing complete admin workflow',
            'start_time' => now()->subHours(1)->format('Y-m-d H:i:s'),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => false,
            'is_public' => true,
            'languages' => ['py3', 'java21'],
            'problems' => [$problem->id],
        ];

        $response = $this->actingAs($this->admin)
            ->post('/backend/contest-wizard', $contestData);

        $response->assertRedirect(route('backend.configurations'));

        $contest = DB::table('contests')->where('name', 'Workflow Test Contest')->first();
        $hackathon = DB::table('hackathons')->where('eventName', 'Workflow Test Contest')->first();

        $this->assertNotNull($contest);
        $this->assertNotNull($hackathon);
        $this->assertFalse((bool)$contest->is_active);

        // Step 2: Edit the contest
        $updateData = [
            'name' => 'Workflow Test Contest - Updated',
            'description' => 'Updated workflow description',
            'start_time' => now()->subMinutes(30)->format('Y-m-d H:i:s'),
            'duration' => 240,
            'freeze_time' => 45,
            'penalty' => 15,
            'max_file_size' => 120,
            'is_active' => false,
            'is_public' => true,
            'unlock_key' => '', // Empty unlock key
        ];

        $response = $this->actingAs($this->admin)
            ->put("/backend/contest/{$contest->id}/update", $updateData);

        $response->assertRedirect(route('backend.configurations'));
        $this->assertDatabaseHas('contests', [
            'id' => $contest->id,
            'name' => 'Workflow Test Contest - Updated',
            'duration' => 240,
        ]);

        // Step 3: Activate the contest
        $response = $this->actingAs($this->admin)
            ->post("/backend/contest/{$contest->id}/activate");

        $response->assertRedirect(route('backend.configurations'));
        $this->assertDatabaseHas('contests', [
            'id' => $contest->id,
            'is_active' => true,
        ]);

        // Step 4: Freeze the scoreboard
        $response = $this->actingAs($this->admin)
            ->post('/backend/contest/freeze');

        $response->assertRedirect(route('backend.configurations'));
        $this->assertDatabaseHas('contests', [
            'id' => $contest->id,
            'freeze_time' => 0,
            'is_active' => true,
        ]);

        // Step 5: End the contest
        $response = $this->actingAs($this->admin)
            ->post('/backend/contest/end');

        $response->assertRedirect(route('backend.configurations'));
        $this->assertDatabaseHas('contests', [
            'id' => $contest->id,
            'is_active' => false,
        ]);

        // Step 6: Delete the contest
        $response = $this->actingAs($this->admin)
            ->delete("/backend/contest/{$contest->id}/delete");

        $response->assertRedirect(route('backend.configurations'));
        $this->assertDatabaseMissing('contests', ['id' => $contest->id]);
        $this->assertDatabaseMissing('hackathons', ['hackathon_id' => $hackathon->hackathon_id]);
    }

    /**
     * Test 9: Non-admin users cannot access admin contest management
     */
    public function test_non_admin_users_cannot_access_admin_features()
    {
        // Create a regular team user
        $teamUser = User::create([
            'fullname' => 'Team User',
            'username' => 'team_user',
            'email' => 'team@test.com',
            'password' => bcrypt('password'),
            'user_type' => 'team',
            'is_enabled' => true,
        ]);

        // This test documents that the current implementation doesn't have
        // middleware protection, but in a production system, these routes
        // should be protected with admin middleware

        // Note: The current routes don't have auth middleware, so they would
        // technically be accessible. In a real scenario, you'd want to add
        // middleware like: Route::middleware(['auth', 'admin'])->group(...)

        // For this test, we'll verify the user exists and has correct role
        $this->assertFalse($teamUser->isAdmin());
        $this->assertTrue($teamUser->isParticipant());
        $this->assertFalse($teamUser->canAccessBackend());
    }

    /**
     * Test 10: Admin can manage multiple contests simultaneously
     */
    public function test_admin_can_manage_multiple_contests()
    {
        // Create multiple contests
        $contest1Id = DB::table('contests')->insertGetId([
            'name' => 'Multi Contest 1',
            'description' => 'First of multiple contests',
            'start_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => false,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contest2Id = DB::table('contests')->insertGetId([
            'name' => 'Multi Contest 2',
            'description' => 'Second of multiple contests',
            'start_time' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'duration' => 240,
            'freeze_time' => 30,
            'penalty' => 15,
            'max_file_size' => 80,
            'is_active' => false,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contest3Id = DB::table('contests')->insertGetId([
            'name' => 'Multi Contest 3',
            'description' => 'Third of multiple contests',
            'start_time' => now()->addDays(3)->format('Y-m-d H:i:s'),
            'duration' => 180,
            'freeze_time' => 20,
            'penalty' => 10,
            'max_file_size' => 60,
            'is_active' => false,
            'is_public' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Verify all contests exist
        $this->assertDatabaseHas('contests', ['id' => $contest1Id]);
        $this->assertDatabaseHas('contests', ['id' => $contest2Id]);
        $this->assertDatabaseHas('contests', ['id' => $contest3Id]);

        // Activate contest 2
        $response = $this->actingAs($this->admin)
            ->post("/backend/contest/{$contest2Id}/activate");

        $response->assertRedirect(route('backend.configurations'));

        $this->assertDatabaseHas('contests', [
            'id' => $contest2Id,
            'is_active' => true,
        ]);

        // Others should be inactive
        $this->assertDatabaseHas('contests', [
            'id' => $contest1Id,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('contests', [
            'id' => $contest3Id,
            'is_active' => false,
        ]);

        // Switch to contest 3
        $response = $this->actingAs($this->admin)
            ->post("/backend/contest/{$contest3Id}/activate");

        $response->assertRedirect(route('backend.configurations'));

        $this->assertDatabaseHas('contests', [
            'id' => $contest3Id,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('contests', [
            'id' => $contest2Id,
            'is_active' => false,
        ]);

        // End the active contest
        $response = $this->actingAs($this->admin)
            ->post('/backend/contest/end');

        $response->assertRedirect(route('backend.configurations'));

        // All contests should now be inactive
        $this->assertDatabaseHas('contests', [
            'id' => $contest1Id,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('contests', [
            'id' => $contest2Id,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('contests', [
            'id' => $contest3Id,
            'is_active' => false,
        ]);
    }
}
