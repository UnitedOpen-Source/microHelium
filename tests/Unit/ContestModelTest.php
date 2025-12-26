<?php

namespace Tests\Unit;

use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use Helium\User;
use App\Models\Answer;
use App\Models\Clarification;
use App\Models\Task;
use App\Models\ContestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContestModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that a contest can be created with valid data
     */
    public function test_contest_can_be_created(): void
    {
        $contest = Contest::create([
            'name' => 'ACM ICPC Regional',
            'description' => 'Annual programming competition',
            'start_time' => now()->addDay(),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->assertInstanceOf(Contest::class, $contest);
        $this->assertDatabaseHas('contests', [
            'name' => 'ACM ICPC Regional',
            'description' => 'Annual programming competition',
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
        ]);
    }

    /**
     * Test that required fields are enforced
     */
    public function test_required_fields(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        // Attempting to create a contest without required fields should fail
        Contest::create([]);
    }

    /**
     * Test that contest name is required
     */
    public function test_name_is_required(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Contest::create([
            'start_time' => now()->addDay(),
            'duration' => 300,
        ]);
    }

    /**
     * Test default values for penalty and freeze_time
     */
    public function test_default_values(): void
    {
        // Create a contest without specifying penalty and freeze_time
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->addDay(),
            'duration' => 300,
        ]);

        // Refresh to get database defaults
        $contest = $contest->fresh();

        $this->assertEquals(20, $contest->penalty);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $contest->freeze_time);
    }

    /**
     * Test default value for penalty is 20
     */
    public function test_penalty_default_value_is_20(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->addDay(),
            'duration' => 300,
        ]);

        $this->assertEquals(20, $contest->fresh()->penalty);
    }

    /**
     * Test default value for freeze_time is 60
     */
    public function test_freeze_time_default_value_is_60(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->addDay(),
            'duration' => 300,
        ]);

        // freeze_time column default in database is 60 minutes
        $this->assertEquals(60, $contest->fresh()->getAttributes()['freeze_time']);
    }

    /**
     * Test that start_time is cast to datetime
     */
    public function test_start_time_is_cast_to_datetime(): void
    {
        $startTime = now()->addDay();

        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => $startTime,
            'duration' => 300,
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $contest->start_time);
        $this->assertEquals($startTime->toDateTimeString(), $contest->start_time->toDateTimeString());
    }

    /**
     * Test that is_active is cast to boolean
     */
    public function test_is_active_is_cast_to_boolean(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->addDay(),
            'duration' => 300,
            'is_active' => 1,
        ]);

        $this->assertIsBool($contest->is_active);
        $this->assertTrue($contest->is_active);
    }

    /**
     * Test that is_public is cast to boolean
     */
    public function test_is_public_is_cast_to_boolean(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->addDay(),
            'duration' => 300,
            'is_public' => 0,
        ]);

        $this->assertIsBool($contest->is_public);
        $this->assertFalse($contest->is_public);
    }

    /**
     * Test hasMany relationship with languages
     */
    public function test_has_many_languages_relationship(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->addDay(),
            'duration' => 300,
        ]);

        $language1 = Language::create([
            'contest_id' => $contest->id,
            'name' => 'Python 3',
            'extension' => 'py',
            'compile_command' => 'python3 -m py_compile {source}',
            'run_command' => 'python3 {source}',
            'is_active' => true,
        ]);

        $language2 = Language::create([
            'contest_id' => $contest->id,
            'name' => 'C++',
            'extension' => 'cpp',
            'compile_command' => 'g++ {source} -o {output}',
            'run_command' => './{executable}',
            'is_active' => true,
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $contest->languages);
        $this->assertCount(2, $contest->languages);
        $this->assertTrue($contest->languages->contains($language1));
        $this->assertTrue($contest->languages->contains($language2));
    }

    /**
     * Test hasMany relationship with problems
     */
    public function test_has_many_problems_relationship(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->addDay(),
            'duration' => 300,
        ]);

        $problem1 = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Problem A',
            'basename' => 'problema',
            'time_limit' => 1000,
            'memory_limit' => 256,
            'output_limit' => 1000,
            'sort_order' => 1,
        ]);

        $problem2 = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'B',
            'name' => 'Problem B',
            'basename' => 'problemb',
            'time_limit' => 2000,
            'memory_limit' => 512,
            'output_limit' => 2000,
            'sort_order' => 2,
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $contest->problems);
        $this->assertCount(2, $contest->problems);
        $this->assertTrue($contest->problems->contains($problem1));
        $this->assertTrue($contest->problems->contains($problem2));
    }

    /**
     * Test that problems are ordered by sort_order
     */
    public function test_problems_are_ordered_by_sort_order(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->addDay(),
            'duration' => 300,
        ]);

        $problem2 = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'B',
            'name' => 'Problem B',
            'basename' => 'problemb',
            'time_limit' => 2000,
            'memory_limit' => 512,
            'output_limit' => 2000,
            'sort_order' => 2,
        ]);

        $problem1 = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Problem A',
            'basename' => 'problema',
            'time_limit' => 1000,
            'memory_limit' => 256,
            'output_limit' => 1000,
            'sort_order' => 1,
        ]);

        $problems = $contest->fresh()->problems;
        $this->assertEquals('Problem A', $problems->first()->name);
        $this->assertEquals('Problem B', $problems->last()->name);
    }

    /**
     * Test hasMany relationship with runs
     */
    public function test_has_many_runs_relationship(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->addDay(),
            'duration' => 300,
        ]);

        $site = Site::create([
            'contest_id' => $contest->id,
            'number' => 1,
            'name' => 'Main Site',
            'ip' => '127.0.0.1',
        ]);

        $user = User::create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'username' => 'testuser',
            'password' => bcrypt('password'),
            'fullname' => 'Test User',
        ]);

        $problem = Problem::create([
            'contest_id' => $contest->id,
            'short_name' => 'A',
            'name' => 'Problem A',
            'basename' => 'problema',
            'time_limit' => 1000,
            'memory_limit' => 256,
            'output_limit' => 1000,
            'sort_order' => 1,
        ]);

        $language = Language::create([
            'contest_id' => $contest->id,
            'name' => 'Python 3',
            'extension' => 'py',
            'compile_command' => 'python3 -m py_compile {source}',
            'run_command' => 'python3 {source}',
            'is_active' => true,
        ]);

        $run1 = Run::create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'user_id' => $user->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'run_number' => 1,
            'filename' => 'solution.py',
            'source_file' => 'submissions/1/solution.py',
            'source_hash' => hash('sha256', 'print("hello")'),
            'contest_time' => 100,
            'status' => 'pending',
        ]);

        $run2 = Run::create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'user_id' => $user->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'run_number' => 2,
            'filename' => 'solution2.py',
            'source_file' => 'submissions/2/solution2.py',
            'source_hash' => hash('sha256', 'print("world")'),
            'contest_time' => 200,
            'status' => 'judged',
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $contest->runs);
        $this->assertCount(2, $contest->runs);
        $this->assertTrue($contest->runs->contains($run1));
        $this->assertTrue($contest->runs->contains($run2));
    }

    /**
     * Test hasMany relationship with sites
     */
    public function test_has_many_sites_relationship(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->addDay(),
            'duration' => 300,
        ]);

        $site = Site::create([
            'contest_id' => $contest->id,
            'number' => 1,
            'name' => 'Main Site',
            'ip' => '127.0.0.1',
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $contest->sites);
        $this->assertCount(1, $contest->sites);
        $this->assertTrue($contest->sites->contains($site));
    }

    /**
     * Test hasMany relationship with users
     */
    public function test_has_many_users_relationship(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->addDay(),
            'duration' => 300,
        ]);

        $site = Site::create([
            'contest_id' => $contest->id,
            'number' => 1,
            'name' => 'Main Site',
            'ip' => '127.0.0.1',
        ]);

        $user = User::create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'username' => 'testuser',
            'password' => bcrypt('password'),
            'fullname' => 'Test User',
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $contest->users);
        $this->assertCount(1, $contest->users);
        $this->assertTrue($contest->users->contains($user));
    }

    /**
     * Test hasMany relationship with answers
     */
    public function test_has_many_answers_relationship(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->addDay(),
            'duration' => 300,
        ]);

        $answer = Answer::create([
            'contest_id' => $contest->id,
            'name' => 'Accepted',
            'short_name' => 'AC',
            'is_accepted' => true,
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $contest->answers);
        $this->assertCount(1, $contest->answers);
        $this->assertTrue($contest->answers->contains($answer));
    }

    /**
     * Test hasMany relationship with clarifications
     */
    public function test_has_many_clarifications_relationship(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->addDay(),
            'duration' => 300,
        ]);

        $site = Site::create([
            'contest_id' => $contest->id,
            'number' => 1,
            'name' => 'Main Site',
            'ip' => '127.0.0.1',
        ]);

        $user = User::create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'username' => 'testuser',
            'password' => bcrypt('password'),
            'fullname' => 'Test User',
        ]);

        $clarification = Clarification::create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'user_id' => $user->user_id,
            'clarification_number' => 1,
            'question' => 'Is input case sensitive?',
            'contest_time' => 100,
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $contest->clarifications);
        $this->assertCount(1, $contest->clarifications);
        $this->assertTrue($contest->clarifications->contains($clarification));
    }

    /**
     * Test hasMany relationship with tasks
     */
    public function test_has_many_tasks_relationship(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->addDay(),
            'duration' => 300,
        ]);

        $site = Site::create([
            'contest_id' => $contest->id,
            'number' => 1,
            'name' => 'Main Site',
            'ip' => '127.0.0.1',
        ]);

        $user = User::create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'username' => 'testuser',
            'password' => bcrypt('password'),
            'fullname' => 'Test User',
        ]);

        $task = Task::create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'user_id' => $user->user_id,
            'task_number' => 1,
            'description' => 'Import test data',
            'contest_time' => 50,
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $contest->tasks);
        $this->assertCount(1, $contest->tasks);
        $this->assertTrue($contest->tasks->contains($task));
    }

    /**
     * Test hasMany relationship with logs
     */
    public function test_has_many_logs_relationship(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->addDay(),
            'duration' => 300,
        ]);

        $log = ContestLog::create([
            'contest_id' => $contest->id,
            'type' => 'info',
            'message' => 'Contest was created',
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $contest->logs);
        $this->assertCount(1, $contest->logs);
        $this->assertTrue($contest->logs->contains($log));
    }

    /**
     * Test getEndTimeAttribute accessor
     */
    public function test_get_end_time_attribute(): void
    {
        $startTime = now();
        $duration = 300; // 300 minutes

        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => $startTime,
            'duration' => $duration,
        ]);

        $expectedEndTime = $startTime->copy()->addMinutes($duration);
        $this->assertEquals($expectedEndTime->timestamp, $contest->end_time->timestamp);
    }

    /**
     * Test getEndTimeAttribute returns null when start_time is null
     */
    public function test_get_end_time_attribute_returns_null_when_start_time_is_null(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => null,
            'duration' => 300,
        ]);

        $this->assertNull($contest->end_time);
    }

    /**
     * Test getFreezeTimeAttribute accessor
     */
    public function test_get_freeze_time_attribute(): void
    {
        $startTime = now();
        $duration = 300; // 300 minutes
        $freezeTime = 60; // 60 minutes before end

        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => $startTime,
            'duration' => $duration,
            'freeze_time' => $freezeTime,
        ]);

        $expectedFreezeTime = $startTime->copy()->addMinutes($duration)->subMinutes($freezeTime);
        $this->assertEquals($expectedFreezeTime->timestamp, $contest->freeze_time->timestamp);
    }

    /**
     * Test isRunning method returns true when contest is running
     */
    public function test_is_running_returns_true_when_contest_is_active_and_running(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->subHour(),
            'duration' => 300,
            'is_active' => true,
        ]);

        $this->assertTrue($contest->isRunning());
    }

    /**
     * Test isRunning method returns false when contest is not active
     */
    public function test_is_running_returns_false_when_contest_is_not_active(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->subHour(),
            'duration' => 300,
            'is_active' => false,
        ]);

        $this->assertFalse($contest->isRunning());
    }

    /**
     * Test isRunning method returns false when contest has not started
     */
    public function test_is_running_returns_false_when_contest_has_not_started(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->addHour(),
            'duration' => 300,
            'is_active' => true,
        ]);

        $this->assertFalse($contest->isRunning());
    }

    /**
     * Test isRunning method returns false when contest has ended
     */
    public function test_is_running_returns_false_when_contest_has_ended(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->subHours(6),
            'duration' => 300,
            'is_active' => true,
        ]);

        $this->assertFalse($contest->isRunning());
    }

    /**
     * Test isFrozen method returns true when contest is in freeze period
     */
    public function test_is_frozen_returns_true_when_in_freeze_period(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->subMinutes(280),
            'duration' => 300,
            'freeze_time' => 60,
            'is_active' => true,
        ]);

        $this->assertTrue($contest->isFrozen());
    }

    /**
     * Test isFrozen method returns false when contest is not in freeze period
     */
    public function test_is_frozen_returns_false_when_not_in_freeze_period(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->subMinutes(30),
            'duration' => 300,
            'freeze_time' => 60,
            'is_active' => true,
        ]);

        $this->assertFalse($contest->isFrozen());
    }

    /**
     * Test getContestTime method returns correct elapsed time
     */
    public function test_get_contest_time_returns_correct_elapsed_time(): void
    {
        $minutesElapsed = 30;
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->subMinutes($minutesElapsed),
            'duration' => 300,
            'is_active' => true,
        ]);

        $expectedSeconds = $minutesElapsed * 60;
        $actualSeconds = $contest->getContestTime();

        // The method returns the time difference (negative when contest has started)
        // diffInSeconds returns negative when start_time is in the past
        // Allow for small time differences (within 2 seconds) due to test execution time
        $this->assertLessThan(0, $actualSeconds);
        $this->assertEqualsWithDelta(-$expectedSeconds, $actualSeconds, 2);
    }

    /**
     * Test getContestTime method returns 0 when contest has not started
     */
    public function test_get_contest_time_returns_zero_when_contest_has_not_started(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->addHour(),
            'duration' => 300,
            'is_active' => true,
        ]);

        $this->assertEquals(0, $contest->getContestTime());
    }

    /**
     * Test soft deletes functionality
     */
    public function test_contest_uses_soft_deletes(): void
    {
        $contest = Contest::create([
            'name' => 'Test Contest',
            'start_time' => now()->addDay(),
            'duration' => 300,
        ]);

        $contestId = $contest->id;
        $contest->delete();

        $this->assertSoftDeleted('contests', ['id' => $contestId]);
        $this->assertNull(Contest::find($contestId));
        $this->assertNotNull(Contest::withTrashed()->find($contestId));
    }

    /**
     * Test fillable attributes
     */
    public function test_fillable_attributes(): void
    {
        $attributes = [
            'name' => 'Test Contest',
            'description' => 'Test Description',
            'start_time' => now()->addDay(),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'unlock_key' => 'secret123',
        ];

        $contest = Contest::create($attributes);

        foreach ($attributes as $key => $value) {
            if ($key === 'start_time') {
                $this->assertEquals($value->toDateTimeString(), $contest->$key->toDateTimeString());
            } elseif ($key === 'freeze_time') {
                // freeze_time is both a database column and an accessor
                // Check the raw attribute value instead
                $this->assertEquals($value, $contest->getAttributes()['freeze_time']);
            } else {
                $this->assertEquals($value, $contest->$key);
            }
        }
    }

    public function test_is_frozen_returns_false_if_contest_not_running()
    {
        $contest = new Contest(['is_active' => false]);
        $this->assertFalse($contest->isFrozen());
    }

    public function test_get_contest_time_returns_zero_for_future_contest()
    {
        $contest = new Contest(['start_time' => now()->addHour()]);
        $this->assertEquals(0, $contest->getContestTime());
    }
}