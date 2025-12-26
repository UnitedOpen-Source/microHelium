<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Problem;
use App\Models\Contest;
use App\Models\TestCase as ProblemTestCase;
use App\Models\Run;
use App\Models\Clarification;
use App\Models\Score;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class ProblemModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that a problem can be created with valid data
     *
     * @return void
     */
    public function testProblemCanBeCreated()
    {
        $contest = Contest::factory()->create();

        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem',
            'basename' => 'test-problem',
            'description' => 'This is a test problem description',
            'color_name' => 'blue',
            'color_hex' => '#0000FF',
            'time_limit' => 2,
            'memory_limit' => 512,
            'output_limit' => 2048,
            'auto_judge' => true,
            'is_fake' => false,
            'sort_order' => 1,
        ]);

        $this->assertInstanceOf(Problem::class, $problem);
        $this->assertEquals('A', $problem->short_name);
        $this->assertEquals('Test Problem', $problem->name);
        $this->assertEquals('test-problem', $problem->basename);
        $this->assertEquals($contest->id, $problem->contest_id);
        $this->assertTrue($problem->auto_judge);
        $this->assertFalse($problem->is_fake);
    }

    /**
     * Test required fields validation
     *
     * @return void
     */
    public function testRequiredFieldsValidation()
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        // Attempt to create a problem without required fields
        Problem::create([
            'name' => 'Test Problem',
        ]);
    }

    /**
     * Test contest_id is required
     *
     * @return void
     */
    public function testContestIdIsRequired()
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Problem::create([
            'short_name' => 'A',
            'name' => 'Test Problem',
            'basename' => 'test-problem',
        ]);
    }

    /**
     * Test short_name is required
     *
     * @return void
     */
    public function testShortNameIsRequired()
    {
        $contest = Contest::factory()->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        Problem::create([
            'contest_id' => $contest->id,
            'name' => 'Test Problem',
            'basename' => 'test-problem',
        ]);
    }

    /**
     * Test name is required
     *
     * @return void
     */
    public function testNameIsRequired()
    {
        $contest = Contest::factory()->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'basename' => 'test-problem',
        ]);
    }

    /**
     * Test basename is required
     *
     * @return void
     */
    public function testBasenameIsRequired()
    {
        $contest = Contest::factory()->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem',
        ]);
    }

    /**
     * Test unique constraint on contest_id and short_name
     *
     * @return void
     */
    public function testUniqueConstraintOnContestIdAndShortName()
    {
        $contest = Contest::factory()->create();

        Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem 1',
            'basename' => 'test-problem-1',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Attempt to create another problem with the same contest_id and short_name
        Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem 2',
            'basename' => 'test-problem-2',
        ]);
    }

    /**
     * Test unique constraint on contest_id and basename
     *
     * @return void
     */
    public function testUniqueConstraintOnContestIdAndBasename()
    {
        $contest = Contest::factory()->create();

        Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem 1',
            'basename' => 'test-problem',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Attempt to create another problem with the same contest_id and basename
        Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'B',
            'name' => 'Test Problem 2',
            'basename' => 'test-problem',
        ]);
    }

    /**
     * Test belongsTo contest relationship
     *
     * @return void
     */
    public function testBelongsToContestRelationship()
    {
        $contest = Contest::factory()->create(['name' => 'Programming Contest']);

        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem',
            'basename' => 'test-problem',
        ]);

        $this->assertInstanceOf(Contest::class, $problem->contest);
        $this->assertEquals($contest->id, $problem->contest->id);
        $this->assertEquals('Programming Contest', $problem->contest->name);
    }

    /**
     * Test hasMany testCases relationship
     *
     * @return void
     */
    public function testHasManyTestCasesRelationship()
    {
        $contest = Contest::factory()->create();
        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem',
            'basename' => 'test-problem',
        ]);

        // Create test cases with all required fields
        ProblemTestCase::create([
            'problem_id' => $problem->id,
            'number' => 1,
            'input_file' => 'input01.txt',
            'output_file' => 'output01.txt',
            'input_hash' => 'abc123',
            'output_hash' => 'def456',
            'is_sample' => true,
        ]);

        ProblemTestCase::create([
            'problem_id' => $problem->id,
            'number' => 2,
            'input_file' => 'input02.txt',
            'output_file' => 'output02.txt',
            'input_hash' => 'ghi789',
            'output_hash' => 'jkl012',
            'is_sample' => false,
        ]);

        $this->assertCount(2, $problem->testCases);
        $this->assertInstanceOf(ProblemTestCase::class, $problem->testCases->first());
        $this->assertEquals(1, $problem->testCases->first()->number);
        $this->assertEquals(2, $problem->testCases->last()->number);
    }

    /**
     * Test hasMany runs relationship
     *
     * @return void
     */
    public function testHasManyRunsRelationship()
    {
        $contest = Contest::factory()->create();
        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem',
            'basename' => 'test-problem',
        ]);

        $user = $this->createTestUser();

        // Create a site for the run
        $siteId = DB::table('sites')->insertGetId([
            'contest_id' => $contest->id,
            'name' => 'Main Site',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Run::create([
            'problem_id' => $problem->id,
            'contest_id' => $contest->id,
            'site_id' => $siteId,
            'user_id' => $user->user_id,
            'language_id' => 1,
            'run_number' => 1,
            'filename' => 'solution.c',
            'source_file' => 'runs/1.c',
            'source_hash' => hash('sha256', 'code'),
            'contest_time' => 100,
            'status' => 'pending',
        ]);

        $this->assertCount(1, $problem->runs);
        $this->assertInstanceOf(Run::class, $problem->runs->first());
    }

    /**
     * Test hasMany clarifications relationship
     *
     * @return void
     */
    public function testHasManyClarificationsRelationship()
    {
        $contest = Contest::factory()->create();
        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem',
            'basename' => 'test-problem',
        ]);

        $user = $this->createTestUser();

        // Create a site for the clarification
        $siteId = DB::table('sites')->insertGetId([
            'contest_id' => $contest->id,
            'name' => 'Main Site',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Clarification::create([
            'problem_id' => $problem->id,
            'contest_id' => $contest->id,
            'site_id' => $siteId,
            'user_id' => $user->user_id,
            'clarification_number' => 1,
            'contest_time' => 100,
            'question' => 'What is the expected output?',
            'answer' => null,
        ]);

        $this->assertCount(1, $problem->clarifications);
        $this->assertInstanceOf(Clarification::class, $problem->clarifications->first());
    }

    /**
     * Test hasMany scores relationship
     *
     * @return void
     */
    public function testHasManyScoresRelationship()
    {
        $contest = Contest::factory()->create();
        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem',
            'basename' => 'test-problem',
        ]);

        $user = $this->createTestUser();

        // Create a site for the score
        $siteId = DB::table('sites')->insertGetId([
            'contest_id' => $contest->id,
            'name' => 'Main Site',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Score::create([
            'problem_id' => $problem->id,
            'contest_id' => $contest->id,
            'site_id' => $siteId,
            'user_id' => $user->user_id,
            'solved' => false,
            'submissions' => 1,
            'pending' => 0,
        ]);

        $this->assertCount(1, $problem->scores);
        $this->assertInstanceOf(Score::class, $problem->scores->first());
    }

    /**
     * Test getPackagePath method
     *
     * @return void
     */
    public function testGetPackagePathMethod()
    {
        $contest = Contest::factory()->create(['id' => 1]);
        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem',
            'basename' => 'test-problem',
        ]);

        $expectedPath = storage_path('app/problems/1/test-problem');
        $this->assertEquals($expectedPath, $problem->getPackagePath());
    }

    /**
     * Test getCompileScriptPath method
     *
     * @return void
     */
    public function testGetCompileScriptPathMethod()
    {
        $contest = Contest::factory()->create(['id' => 1]);
        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem',
            'basename' => 'test-problem',
        ]);

        $expectedPath = storage_path('app/problems/1/test-problem/compile/cpp');
        $this->assertEquals($expectedPath, $problem->getCompileScriptPath('cpp'));
    }

    /**
     * Test getRunScriptPath method
     *
     * @return void
     */
    public function testGetRunScriptPathMethod()
    {
        $contest = Contest::factory()->create(['id' => 1]);
        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem',
            'basename' => 'test-problem',
        ]);

        $expectedPath = storage_path('app/problems/1/test-problem/run/python');
        $this->assertEquals($expectedPath, $problem->getRunScriptPath('python'));
    }

    /**
     * Test getCompareScriptPath method
     *
     * @return void
     */
    public function testGetCompareScriptPathMethod()
    {
        $contest = Contest::factory()->create(['id' => 1]);
        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem',
            'basename' => 'test-problem',
        ]);

        $expectedPath = storage_path('app/problems/1/test-problem/compare/java');
        $this->assertEquals($expectedPath, $problem->getCompareScriptPath('java'));
    }

    /**
     * Test getLimitsScriptPath method
     *
     * @return void
     */
    public function testGetLimitsScriptPathMethod()
    {
        $contest = Contest::factory()->create(['id' => 1]);
        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem',
            'basename' => 'test-problem',
        ]);

        $expectedPath = storage_path('app/problems/1/test-problem/limits/go');
        $this->assertEquals($expectedPath, $problem->getLimitsScriptPath('go'));
    }

    /**
     * Test getInputPath method
     *
     * @return void
     */
    public function testGetInputPathMethod()
    {
        $contest = Contest::factory()->create(['id' => 1]);
        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem',
            'basename' => 'test-problem',
        ]);

        $expectedPath = storage_path('app/problems/1/test-problem/input/1');
        $this->assertEquals($expectedPath, $problem->getInputPath(1));
    }

    /**
     * Test getOutputPath method
     *
     * @return void
     */
    public function testGetOutputPathMethod()
    {
        $contest = Contest::factory()->create(['id' => 1]);
        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem',
            'basename' => 'test-problem',
        ]);

        $expectedPath = storage_path('app/problems/1/test-problem/output/1');
        $this->assertEquals($expectedPath, $problem->getOutputPath(1));
    }

    /**
     * Test boolean casts for auto_judge
     *
     * @return void
     */
    public function testAutoJudgeBooleanCast()
    {
        $contest = Contest::factory()->create();
        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem',
            'basename' => 'test-problem',
            'auto_judge' => 1,
        ]);

        $this->assertIsBool($problem->auto_judge);
        $this->assertTrue($problem->auto_judge);
    }

    /**
     * Test boolean casts for is_fake
     *
     * @return void
     */
    public function testIsFakeBooleanCast()
    {
        $contest = Contest::factory()->create();
        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem',
            'basename' => 'test-problem',
            'is_fake' => 0,
        ]);

        $this->assertIsBool($problem->is_fake);
        $this->assertFalse($problem->is_fake);
    }

    /**
     * Test soft deletes functionality
     *
     * @return void
     */
    public function testSoftDeletes()
    {
        $contest = Contest::factory()->create();
        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem',
            'basename' => 'test-problem',
        ]);

        $problemId = $problem->id;

        // Delete the problem
        $problem->delete();

        // Problem should not be found in normal queries
        $this->assertNull(Problem::find($problemId));

        // Problem should be found with trashed
        $this->assertNotNull(Problem::withTrashed()->find($problemId));
        $this->assertTrue(Problem::withTrashed()->find($problemId)->trashed());
    }

    /**
     * Test default values - checks database defaults or nullable fields
     *
     * @return void
     */
    public function testDefaultValues()
    {
        $contest = Contest::factory()->create();
        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Test Problem',
            'basename' => 'test-problem',
        ]);

        // Refresh from database to get database defaults
        $problem->refresh();

        // These fields should either have defaults or be nullable
        // Just verify the problem was created successfully
        $this->assertNotNull($problem->id);
        $this->assertEquals('A', $problem->short_name);
        $this->assertEquals('Test Problem', $problem->name);
        $this->assertEquals('test-problem', $problem->basename);

        // If time_limit has a default, it should be a positive number
        if ($problem->time_limit !== null) {
            $this->assertGreaterThan(0, $problem->time_limit);
        }
    }

    /**
     * Test fillable attributes
     *
     * @return void
     */
    public function testFillableAttributes()
    {
        $contest = Contest::factory()->create();

        $data = [
            'contest_id' => $contest->id,
            'short_name' => 'B',
            'name' => 'Advanced Problem',
            'basename' => 'advanced-problem',
            'description' => 'Complex problem description',
            'description_file' => 'problem.pdf',
            'input_file' => 'input.txt',
            'input_file_hash' => hash('sha256', 'test'),
            'color_name' => 'red',
            'color_hex' => '#FF0000',
            'time_limit' => 3,
            'memory_limit' => 1024,
            'output_limit' => 4096,
            'auto_judge' => false,
            'is_fake' => true,
            'sort_order' => 5,
        ];

        $problem = Problem::create($data);

        foreach ($data as $key => $value) {
            $this->assertEquals($value, $problem->$key);
        }
    }
}
