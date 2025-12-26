<?php

namespace Tests\Integration;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\Language;
use App\Models\ProblemBank;

class ContestCreationTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Create an admin user for testing
        $this->admin = $this->createUser([
            'user_type' => 'admin',
            'fullname' => 'Admin User',
            'username' => 'admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_enabled' => true,
        ]);
    }

    /**
     * Helper to create a user
     */
    protected function createUser(array $attributes = []): \Helium\User
    {
        return \Helium\User::create(array_merge([
            'fullname' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'team',
            'is_enabled' => true,
        ], $attributes));
    }

    /**
     * Test 1: Contest wizard page loads correctly
     */
    public function test_contest_wizard_page_loads_correctly()
    {
        // Create some test problems in the problem bank
        $problem1 = ProblemBank::create([
            'code' => 'TEST001',
            'name' => 'Test Problem 1',
            'description' => 'Test description 1',
            'input_description' => 'Input desc',
            'output_description' => 'Output desc',
            'time_limit' => 1000,
            'memory_limit' => 256,
            'difficulty' => 'easy',
            'is_active' => true,
        ]);

        $problem2 = ProblemBank::create([
            'code' => 'TEST002',
            'name' => 'Test Problem 2',
            'description' => 'Test description 2',
            'input_description' => 'Input desc',
            'output_description' => 'Output desc',
            'time_limit' => 2000,
            'memory_limit' => 512,
            'difficulty' => 'medium',
            'is_active' => true,
        ]);

        // Act as admin and visit the contest wizard page
        $response = $this->actingAs($this->admin)
            ->get('/backend/contest-wizard');

        // Assert the page loads successfully
        $response->assertStatus(200);
        $response->assertViewIs('backend.contest-wizard');
        $response->assertViewHas('problemBank');

        // Verify the problem bank data is passed to the view
        $problemBank = $response->viewData('problemBank');
        $this->assertCount(2, $problemBank);
        $this->assertEquals('Test Problem 1', $problemBank->first()->name);
    }

    /**
     * Test 2: Contest can be created via POST to /backend/contest-wizard
     */
    public function test_contest_can_be_created_successfully()
    {
        // Create test problems in problem bank
        $problem1 = ProblemBank::create([
            'code' => 'PROB001',
            'name' => 'Problem Alpha',
            'description' => 'Problem description',
            'input_description' => 'Input',
            'output_description' => 'Output',
            'time_limit' => 1000,
            'memory_limit' => 256,
            'difficulty' => 'easy',
            'is_active' => true,
        ]);

        $problem2 = ProblemBank::create([
            'code' => 'PROB002',
            'name' => 'Problem Beta',
            'description' => 'Problem description',
            'input_description' => 'Input',
            'output_description' => 'Output',
            'time_limit' => 2000,
            'memory_limit' => 512,
            'difficulty' => 'medium',
            'is_active' => true,
        ]);

        $contestData = [
            'name' => 'Test Contest 2024',
            'description' => 'A test contest for integration testing',
            'start_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'languages' => ['c_gcc13', 'cpp_gpp13', 'py3', 'java21'],
            'problems' => [$problem1->id, $problem2->id],
        ];

        // Submit the contest creation request
        $response = $this->actingAs($this->admin)
            ->post('/backend/contest-wizard', $contestData);

        // Assert redirect to configurations page with success message
        $response->assertRedirect(route('backend.configurations'));
        $response->assertSessionHas('success');

        // Verify the contest was created in the database
        $this->assertDatabaseHas('contests', [
            'name' => 'Test Contest 2024',
            'description' => 'A test contest for integration testing',
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
        ]);

        // Verify hackathon was created
        $this->assertDatabaseHas('hackathons', [
            'eventName' => 'Test Contest 2024',
            'description' => 'A test contest for integration testing',
        ]);

        // Verify the contest exists
        $contest = DB::table('contests')->where('name', 'Test Contest 2024')->first();
        $this->assertNotNull($contest);

        // Verify site was created
        $this->assertDatabaseHas('sites', [
            'contest_id' => $contest->id,
            'name' => 'Main Site',
            'is_active' => true,
            'permit_logins' => true,
        ]);

        // Verify default answers were created
        $answersCount = DB::table('answers')->where('contest_id', $contest->id)->count();
        $this->assertEquals(8, $answersCount); // 8 default answer types

        // Verify accepted answer exists
        $this->assertDatabaseHas('answers', [
            'contest_id' => $contest->id,
            'short_name' => 'AC',
            'name' => 'Accepted',
            'is_accepted' => true,
        ]);
    }

    /**
     * Test 3: Duplicate contest names are rejected
     */
    public function test_duplicate_contest_names_are_rejected()
    {
        // Create an initial contest via hackathon
        DB::table('hackathons')->insert([
            'eventName' => 'Existing Contest',
            'description' => 'Original contest',
            'starts_at' => now()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addHours(5)->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contestData = [
            'name' => 'Existing Contest', // Duplicate name
            'description' => 'Attempting to create duplicate',
            'start_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'languages' => ['c_gcc13'],
            'problems' => [],
        ];

        // Attempt to create contest with duplicate name
        $response = $this->actingAs($this->admin)
            ->post('/backend/contest-wizard', $contestData);

        // Assert redirect back with validation error for the name field
        $response->assertRedirect();
        $response->assertSessionHasErrors('name');

        // Verify only one hackathon with this name exists
        $count = DB::table('hackathons')->where('eventName', 'Existing Contest')->count();
        $this->assertEquals(1, $count);
    }

    /**
     * Test 4: Required fields are validated (name, start_time, duration)
     */
    public function test_required_fields_are_validated()
    {
        // Test with empty data
        $response = $this->actingAs($this->admin)
            ->post('/backend/contest-wizard', []);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['name', 'start_time', 'duration', 'freeze_time', 'penalty', 'max_file_size']);
    }

    /**
     * Test 5: Languages are associated with contest
     */
    public function test_languages_are_associated_with_contest()
    {
        $contestData = [
            'name' => 'Multi-Language Contest',
            'description' => 'Contest with multiple languages',
            'start_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'languages' => ['c_gcc13', 'cpp_gpp13', 'py3', 'java21', 'rs'],
            'problems' => [],
        ];

        // Create the contest
        $response = $this->actingAs($this->admin)
            ->post('/backend/contest-wizard', $contestData);

        $response->assertRedirect(route('backend.configurations'));

        // Get the created contest
        $contest = DB::table('contests')->where('name', 'Multi-Language Contest')->first();
        $this->assertNotNull($contest);

        // Verify selected languages are active
        $this->assertDatabaseHas('languages', [
            'contest_id' => $contest->id,
            'name' => 'C (GCC 13)',
            'extension' => 'c_gcc13',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('languages', [
            'contest_id' => $contest->id,
            'name' => 'C++ (G++ 13)',
            'extension' => 'cpp_gpp13',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('languages', [
            'contest_id' => $contest->id,
            'name' => 'Python 3.12',
            'extension' => 'py3',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('languages', [
            'contest_id' => $contest->id,
            'name' => 'Java (OpenJDK 21)',
            'extension' => 'java21',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('languages', [
            'contest_id' => $contest->id,
            'name' => 'Rust (1.75)',
            'extension' => 'rs',
            'is_active' => true,
        ]);

        // Verify non-selected languages are inactive
        $inactiveLanguages = DB::table('languages')
            ->where('contest_id', $contest->id)
            ->where('is_active', false)
            ->count();

        // Total default languages minus the 5 we selected
        $defaultLanguagesCount = count(Language::getDefaultLanguages());
        $this->assertEquals($defaultLanguagesCount - 5, $inactiveLanguages);

        // Verify all default languages are created (both active and inactive)
        $totalLanguages = DB::table('languages')
            ->where('contest_id', $contest->id)
            ->count();

        $this->assertEquals($defaultLanguagesCount, $totalLanguages);
    }

    /**
     * Test 6: Problems are associated with contest
     */
    public function test_problems_are_associated_with_contest()
    {
        // Create test problems in the problem bank
        $problem1 = ProblemBank::create([
            'code' => 'ARRAY001',
            'name' => 'Array Sum',
            'description' => 'Calculate sum of array elements',
            'input_description' => 'Array of integers',
            'output_description' => 'Sum as integer',
            'time_limit' => 1000,
            'memory_limit' => 256,
            'difficulty' => 'easy',
            'is_active' => true,
        ]);

        $problem2 = ProblemBank::create([
            'code' => 'GRAPH001',
            'name' => 'Shortest Path',
            'description' => 'Find shortest path in graph',
            'input_description' => 'Graph adjacency list',
            'output_description' => 'Shortest distance',
            'time_limit' => 2000,
            'memory_limit' => 512,
            'difficulty' => 'medium',
            'is_active' => true,
        ]);

        $problem3 = ProblemBank::create([
            'code' => 'DP001',
            'name' => 'Knapsack Problem',
            'description' => 'Solve 0/1 knapsack',
            'input_description' => 'Weights and values',
            'output_description' => 'Maximum value',
            'time_limit' => 3000,
            'memory_limit' => 1024,
            'difficulty' => 'hard',
            'is_active' => true,
        ]);

        $contestData = [
            'name' => 'Problem Set Contest',
            'description' => 'Contest with multiple problems',
            'start_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'languages' => ['c_gcc13', 'py3'],
            'problems' => [$problem1->id, $problem2->id, $problem3->id],
        ];

        // Create the contest
        $response = $this->actingAs($this->admin)
            ->post('/backend/contest-wizard', $contestData);

        $response->assertRedirect(route('backend.configurations'));
        $response->assertSessionHas('success');

        // Verify success message mentions the number of problems
        $successMessage = session('success');
        $this->assertStringContainsString('3 problemas', $successMessage);

        // Get the created contest
        $contest = DB::table('contests')->where('name', 'Problem Set Contest')->first();
        $this->assertNotNull($contest);

        // Verify problems were imported from problem bank
        $contestProblems = DB::table('problems')
            ->where('contest_id', $contest->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(3, $contestProblems);

        // Verify first problem (should be letter A)
        $this->assertEquals('A', $contestProblems[0]->short_name);
        $this->assertEquals('Array Sum', $contestProblems[0]->name);
        $this->assertEquals(1000, $contestProblems[0]->time_limit);
        $this->assertEquals(256, $contestProblems[0]->memory_limit);
        $this->assertEquals(0, $contestProblems[0]->sort_order);
        $this->assertTrue((bool) $contestProblems[0]->auto_judge);
        $this->assertFalse((bool) $contestProblems[0]->is_fake);

        // Verify second problem (should be letter B)
        $this->assertEquals('B', $contestProblems[1]->short_name);
        $this->assertEquals('Shortest Path', $contestProblems[1]->name);
        $this->assertEquals(2000, $contestProblems[1]->time_limit);
        $this->assertEquals(512, $contestProblems[1]->memory_limit);
        $this->assertEquals(1, $contestProblems[1]->sort_order);

        // Verify third problem (should be letter C)
        $this->assertEquals('C', $contestProblems[2]->short_name);
        $this->assertEquals('Knapsack Problem', $contestProblems[2]->name);
        $this->assertEquals(3000, $contestProblems[2]->time_limit);
        $this->assertEquals(1024, $contestProblems[2]->memory_limit);
        $this->assertEquals(2, $contestProblems[2]->sort_order);

        // Verify problem descriptions include input/output sections
        $this->assertStringContainsString('## Entrada', $contestProblems[0]->description);
        $this->assertStringContainsString('## Saida', $contestProblems[0]->description);

        // Verify balloon colors were assigned
        $this->assertNotNull($contestProblems[0]->color_name);
        $this->assertNotNull($contestProblems[0]->color_hex);
        $this->assertEquals('Vermelho', $contestProblems[0]->color_name);
        $this->assertEquals('#EF4444', $contestProblems[0]->color_hex);

        $this->assertEquals('Azul', $contestProblems[1]->color_name);
        $this->assertEquals('#3B82F6', $contestProblems[1]->color_hex);

        $this->assertEquals('Verde', $contestProblems[2]->color_name);
        $this->assertEquals('#22C55E', $contestProblems[2]->color_hex);
    }

    /**
     * Test 7: Contest creation without problems
     */
    public function test_contest_can_be_created_without_problems()
    {
        $contestData = [
            'name' => 'Empty Contest',
            'description' => 'Contest without any problems',
            'start_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'duration' => 240,
            'freeze_time' => 30,
            'penalty' => 15,
            'max_file_size' => 50,
            'is_active' => false,
            'is_public' => true,
            'languages' => ['py3'],
            'problems' => [],
        ];

        $response = $this->actingAs($this->admin)
            ->post('/backend/contest-wizard', $contestData);

        $response->assertRedirect(route('backend.configurations'));
        $response->assertSessionHas('success');

        // Verify contest was created
        $contest = DB::table('contests')->where('name', 'Empty Contest')->first();
        $this->assertNotNull($contest);

        // Verify no problems were created
        $problemCount = DB::table('problems')->where('contest_id', $contest->id)->count();
        $this->assertEquals(0, $problemCount);

        // Success message should mention 0 problems
        $successMessage = session('success');
        $this->assertStringContainsString('0 problemas', $successMessage);
    }

    /**
     * Test 8: Contest creation with custom parameters
     */
    public function test_contest_creation_with_custom_parameters()
    {
        $contestData = [
            'name' => 'Custom Parameters Contest',
            'description' => 'Testing custom freeze, penalty, and file size',
            'start_time' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'duration' => 420, // 7 hours
            'freeze_time' => 120, // 2 hours before end
            'penalty' => 30, // 30 minutes penalty
            'max_file_size' => 200, // 200 KB
            'is_active' => false,
            'is_public' => false,
            'languages' => [],
            'problems' => [],
        ];

        $response = $this->actingAs($this->admin)
            ->post('/backend/contest-wizard', $contestData);

        $response->assertRedirect(route('backend.configurations'));

        // Verify custom parameters were saved
        $this->assertDatabaseHas('contests', [
            'name' => 'Custom Parameters Contest',
            'duration' => 420,
            'freeze_time' => 120,
            'penalty' => 30,
            'max_file_size' => 200,
            'is_active' => false,
            'is_public' => false,
        ]);

        // Verify hackathon end time is calculated correctly
        $hackathon = DB::table('hackathons')
            ->where('eventName', 'Custom Parameters Contest')
            ->first();

        $this->assertNotNull($hackathon);

        $startTime = new \DateTime($hackathon->starts_at);
        $endTime = new \DateTime($hackathon->ends_at);
        $diffMinutes = ($endTime->getTimestamp() - $startTime->getTimestamp()) / 60;

        $this->assertEquals(420, $diffMinutes);
    }
}
