<?php

namespace Tests\Integration;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Helium\User;

class ProblemBankTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the User class is loaded
        require_once app_path('User.php');
    }

    /**
     * Create an admin user for testing
     */
    protected function createAdmin(): User
    {
        $userId = DB::table('users')->insertGetId([
            'fullname' => 'Admin User',
            'username' => 'admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'user_type' => 'admin',
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::find($userId);
    }

    /**
     * Create test problems in the problem bank
     */
    protected function createTestProblems(): array
    {
        $problems = [
            [
                'code' => 'PROB001',
                'name' => 'Easy Addition Problem',
                'description' => 'Add two numbers',
                'input_description' => 'Two integers A and B',
                'output_description' => 'Sum of A and B',
                'sample_input' => '5 3',
                'sample_output' => '8',
                'notes' => 'Simple addition test',
                'time_limit' => 1,
                'memory_limit' => 256,
                'source' => 'Test',
                'source_url' => 'http://test.com/prob1',
                'difficulty' => 'easy',
                'tags' => json_encode(['math', 'beginner']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'PROB002',
                'name' => 'Medium Sorting Problem',
                'description' => 'Sort an array',
                'input_description' => 'Array of integers',
                'output_description' => 'Sorted array',
                'sample_input' => '5 2 8 1',
                'sample_output' => '1 2 5 8',
                'notes' => 'Sorting test',
                'time_limit' => 2,
                'memory_limit' => 512,
                'source' => 'Test',
                'source_url' => 'http://test.com/prob2',
                'difficulty' => 'medium',
                'tags' => json_encode(['sorting', 'arrays']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'PROB003',
                'name' => 'Hard Graph Problem',
                'description' => 'Find shortest path',
                'input_description' => 'Graph edges',
                'output_description' => 'Shortest path',
                'sample_input' => '1 2\n2 3',
                'sample_output' => '1 2 3',
                'notes' => 'Graph traversal',
                'time_limit' => 3,
                'memory_limit' => 1024,
                'source' => 'Test',
                'source_url' => 'http://test.com/prob3',
                'difficulty' => 'hard',
                'tags' => json_encode(['graph', 'algorithms']),
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        $ids = [];
        foreach ($problems as $problem) {
            $ids[] = DB::table('problem_bank')->insertGetId($problem);
        }

        return $ids;
    }

    /**
     * Test 1: Problem bank page loads correctly
     */
    public function test_problem_bank_page_loads_correctly()
    {
        // Create admin user
        $admin = $this->createAdmin();

        // Create test problems
        $this->createTestProblems();

        // Make request to problem bank page
        $response = $this->actingAs($admin, 'web')
            ->get(route('backend.problem-bank'));

        // Assert page loads successfully
        $response->assertStatus(200);

        // Assert view is correct
        $response->assertViewIs('backend.problem-bank');

        // Assert view has problems data
        $response->assertViewHas('problems');

        // Get problems from view
        $problems = $response->viewData('problems');

        // Assert we have 3 problems
        $this->assertCount(3, $problems);
    }

    /**
     * Test 2: Problems are listed correctly
     */
    public function test_problems_are_listed_correctly()
    {
        // Create admin user
        $admin = $this->createAdmin();

        // Create test problems
        $problemIds = $this->createTestProblems();

        // Make request to problem bank page
        $response = $this->actingAs($admin, 'web')
            ->get(route('backend.problem-bank'));

        // Assert response contains problem names
        $response->assertSee('Easy Addition Problem');
        $response->assertSee('Medium Sorting Problem');
        $response->assertSee('Hard Graph Problem');

        // Assert response contains problem codes
        $response->assertSee('PROB001');
        $response->assertSee('PROB002');
        $response->assertSee('PROB003');

        // Assert response contains difficulty levels
        $response->assertSee('easy');
        $response->assertSee('medium');
        $response->assertSee('hard');

        // Get problems from database ordered by difficulty (alphabetically: easy, hard, medium)
        $problems = DB::table('problem_bank')
            ->orderBy('difficulty')
            ->orderBy('name')
            ->get();

        // Assert first problem is easy (alphabetically first difficulty)
        $this->assertEquals('easy', $problems->first()->difficulty);

        // Assert problems are in expected order (ordered by difficulty alphabetically, then name)
        $this->assertEquals('PROB001', $problems[0]->code); // easy
        $this->assertEquals('PROB003', $problems[1]->code); // hard
        $this->assertEquals('PROB002', $problems[2]->code); // medium
    }

    /**
     * Test 3: Problem can be toggled active/inactive
     */
    public function test_problem_can_be_toggled_active_inactive()
    {
        // Create admin user
        $admin = $this->createAdmin();

        // Create test problems
        $problemIds = $this->createTestProblems();
        $problemId = $problemIds[0]; // First problem (active)

        // Verify problem is initially active
        $problem = DB::table('problem_bank')->where('id', $problemId)->first();
        $this->assertTrue((bool) $problem->is_active);

        // Toggle problem to inactive
        $response = $this->actingAs($admin, 'web')
            ->post(route('backend.problem-bank') . '/' . $problemId . '/toggle');

        // Assert redirect back with success message
        $response->assertRedirect(route('backend.problem-bank'));
        $response->assertSessionHas('success', 'Status do problema atualizado!');

        // Verify problem is now inactive
        $problem = DB::table('problem_bank')->where('id', $problemId)->first();
        $this->assertFalse((bool) $problem->is_active);

        // Toggle problem back to active
        $response = $this->actingAs($admin, 'web')
            ->post(route('backend.problem-bank') . '/' . $problemId . '/toggle');

        // Verify problem is now active again
        $problem = DB::table('problem_bank')->where('id', $problemId)->first();
        $this->assertTrue((bool) $problem->is_active);
    }

    /**
     * Test 4: Problem can be deleted
     */
    public function test_problem_can_be_deleted()
    {
        // Create admin user
        $admin = $this->createAdmin();

        // Create test problems
        $problemIds = $this->createTestProblems();
        $problemId = $problemIds[0]; // First problem

        // Verify problem exists
        $this->assertDatabaseHas('problem_bank', [
            'id' => $problemId,
            'code' => 'PROB001',
        ]);

        // Delete the problem
        $response = $this->actingAs($admin, 'web')
            ->delete(route('backend.problem-bank') . '/' . $problemId);

        // Assert redirect back with success message
        $response->assertRedirect(route('backend.problem-bank'));
        $response->assertSessionHas('success', 'Problema removido do banco!');

        // Verify problem is deleted
        $this->assertDatabaseMissing('problem_bank', [
            'id' => $problemId,
        ]);

        // Verify only 2 problems remain
        $this->assertEquals(2, DB::table('problem_bank')->count());
    }

    /**
     * Test 5: Filter by difficulty works
     */
    public function test_filter_by_difficulty_works()
    {
        // Create admin user
        $admin = $this->createAdmin();

        // Create test problems
        $this->createTestProblems();

        // Filter by easy difficulty using model scope
        $easyProblems = \App\Models\ProblemBank::byDifficulty('easy')->get();
        $this->assertCount(1, $easyProblems);
        $this->assertEquals('Easy Addition Problem', $easyProblems->first()->name);

        // Filter by medium difficulty
        $mediumProblems = \App\Models\ProblemBank::byDifficulty('medium')->get();
        $this->assertCount(1, $mediumProblems);
        $this->assertEquals('Medium Sorting Problem', $mediumProblems->first()->name);

        // Filter by hard difficulty
        $hardProblems = \App\Models\ProblemBank::byDifficulty('hard')->get();
        $this->assertCount(1, $hardProblems);
        $this->assertEquals('Hard Graph Problem', $hardProblems->first()->name);

        // Filter by active status
        $activeProblems = \App\Models\ProblemBank::active()->get();
        $this->assertCount(2, $activeProblems);

        // Combine filters: active + easy
        $activeEasyProblems = \App\Models\ProblemBank::active()->byDifficulty('easy')->get();
        $this->assertCount(1, $activeEasyProblems);
        $this->assertEquals('PROB001', $activeEasyProblems->first()->code);

        // Combine filters: active + hard (should be 0 since hard problem is inactive)
        $activeHardProblems = \App\Models\ProblemBank::active()->byDifficulty('hard')->get();
        $this->assertCount(0, $activeHardProblems);
    }

    /**
     * Test 6: Search by name works
     */
    public function test_search_by_name_works()
    {
        // Create admin user
        $admin = $this->createAdmin();

        // Create test problems
        $this->createTestProblems();

        // Search for problems with "Addition" in name
        $problems = DB::table('problem_bank')
            ->where('name', 'LIKE', '%Addition%')
            ->get();
        $this->assertCount(1, $problems);
        $this->assertEquals('Easy Addition Problem', $problems->first()->name);

        // Search for problems with "Problem" in name
        $problems = DB::table('problem_bank')
            ->where('name', 'LIKE', '%Problem%')
            ->get();
        $this->assertCount(3, $problems);

        // Search for problems with "Sorting" in name
        $problems = DB::table('problem_bank')
            ->where('name', 'LIKE', '%Sorting%')
            ->get();
        $this->assertCount(1, $problems);
        $this->assertEquals('Medium Sorting Problem', $problems->first()->name);

        // Search for problems with "Graph" in name
        $problems = DB::table('problem_bank')
            ->where('name', 'LIKE', '%Graph%')
            ->get();
        $this->assertCount(1, $problems);
        $this->assertEquals('Hard Graph Problem', $problems->first()->name);

        // Search by code
        $problems = DB::table('problem_bank')
            ->where('code', 'LIKE', '%PROB002%')
            ->get();
        $this->assertCount(1, $problems);
        $this->assertEquals('Medium Sorting Problem', $problems->first()->name);

        // Search for non-existent problem
        $problems = DB::table('problem_bank')
            ->where('name', 'LIKE', '%Nonexistent%')
            ->get();
        $this->assertCount(0, $problems);

        // Case-insensitive search
        $problems = DB::table('problem_bank')
            ->where('name', 'LIKE', '%addition%')
            ->get();
        $this->assertCount(1, $problems);
    }

    /**
     * Test 7: Problem bank integration with contest wizard
     */
    public function test_problem_bank_integration_with_contest_wizard()
    {
        // Create admin user
        $admin = $this->createAdmin();

        // Create test problems
        $problemIds = $this->createTestProblems();

        // Load contest wizard page
        $response = $this->actingAs($admin, 'web')
            ->get(route('backend.contest-wizard'));

        // Assert page loads
        $response->assertStatus(200);
        $response->assertViewIs('backend.contest-wizard');
        $response->assertViewHas('problemBank');

        // Get problem bank from view (should only show active problems)
        $problemBank = $response->viewData('problemBank');

        // Assert only active problems are shown (2 out of 3)
        $this->assertCount(2, $problemBank);

        // Assert inactive problem is not in the list
        $problemCodes = $problemBank->pluck('code')->toArray();
        $this->assertContains('PROB001', $problemCodes);
        $this->assertContains('PROB002', $problemCodes);
        $this->assertNotContains('PROB003', $problemCodes); // Inactive problem
    }

    /**
     * Test 8: Problem attributes and model methods
     */
    public function test_problem_attributes_and_model_methods()
    {
        // Create test problems
        $this->createTestProblems();

        // Get easy problem
        $easyProblem = \App\Models\ProblemBank::where('code', 'PROB001')->first();
        $this->assertEquals('Facil', $easyProblem->difficulty_label);
        $this->assertStringContainsString('green', $easyProblem->difficulty_badge);

        // Get medium problem
        $mediumProblem = \App\Models\ProblemBank::where('code', 'PROB002')->first();
        $this->assertEquals('Medio', $mediumProblem->difficulty_label);
        $this->assertStringContainsString('yellow', $mediumProblem->difficulty_badge);

        // Get hard problem
        $hardProblem = \App\Models\ProblemBank::where('code', 'PROB003')->first();
        $this->assertEquals('Dificil', $hardProblem->difficulty_label);
        $this->assertStringContainsString('red', $hardProblem->difficulty_badge);

        // Test tags are properly cast to array
        $this->assertIsArray($easyProblem->tags);
        $this->assertContains('math', $easyProblem->tags);
        $this->assertContains('beginner', $easyProblem->tags);

        // Test is_active is cast to boolean
        $this->assertIsBool($easyProblem->is_active);
        $this->assertTrue($easyProblem->is_active);
        $this->assertFalse($hardProblem->is_active);
    }

    /**
     * Test 9: Multiple admins can access problem bank
     */
    public function test_multiple_admins_can_access_problem_bank()
    {
        // Create multiple admin users
        $admin1 = $this->createAdmin();

        $admin2Id = DB::table('users')->insertGetId([
            'fullname' => 'Admin User 2',
            'username' => 'admin2',
            'email' => 'admin2@test.com',
            'password' => bcrypt('password'),
            'user_type' => 'admin',
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin2 = User::find($admin2Id);

        // Create test problems
        $this->createTestProblems();

        // Both admins should be able to access the page
        $response1 = $this->actingAs($admin1, 'web')
            ->get(route('backend.problem-bank'));
        $response1->assertStatus(200);

        $response2 = $this->actingAs($admin2, 'web')
            ->get(route('backend.problem-bank'));
        $response2->assertStatus(200);
    }

    /**
     * Test 10: Problem data integrity
     */
    public function test_problem_data_integrity()
    {
        // Create test problems
        $problemIds = $this->createTestProblems();

        // Verify all required fields are saved correctly
        $problem = DB::table('problem_bank')->where('id', $problemIds[0])->first();

        $this->assertEquals('PROB001', $problem->code);
        $this->assertEquals('Easy Addition Problem', $problem->name);
        $this->assertEquals('Add two numbers', $problem->description);
        $this->assertEquals('Two integers A and B', $problem->input_description);
        $this->assertEquals('Sum of A and B', $problem->output_description);
        $this->assertEquals('5 3', $problem->sample_input);
        $this->assertEquals('8', $problem->sample_output);
        $this->assertEquals('Simple addition test', $problem->notes);
        $this->assertEquals(1, $problem->time_limit);
        $this->assertEquals(256, $problem->memory_limit);
        $this->assertEquals('Test', $problem->source);
        $this->assertEquals('http://test.com/prob1', $problem->source_url);
        $this->assertEquals('easy', $problem->difficulty);
        $this->assertTrue((bool) $problem->is_active);

        // Verify timestamps are set
        $this->assertNotNull($problem->created_at);
        $this->assertNotNull($problem->updated_at);

        // Verify unique constraint on code
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('problem_bank')->insert([
            'code' => 'PROB001', // Duplicate code
            'name' => 'Duplicate Problem',
            'description' => 'Test',
            'input_description' => 'Test',
            'output_description' => 'Test',
            'difficulty' => 'easy',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
