<?php

namespace Tests\Feature\Backend;

use App\Models\Contest;
use App\Models\Problem;
use App\Models\ProblemBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProblemManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access_problem_management()
    {
        $team = $this->createTestUser(['user_type' => 'team']);

        $response = $this->actingAs($team)->get('/backend/exercises');

        $response->assertStatus(403);
    }

    public function test_admin_sees_existing_problems_for_the_selected_contest()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        Problem::factory()->create(['contest_id' => $contest->id, 'name' => 'Already There']);

        $response = $this->actingAs($admin)->get('/backend/exercises?contest_id=' . $contest->id);

        $response->assertStatus(200);
        $response->assertViewIs('backend.problems');
        $response->assertSeeText('Already There');
    }

    public function test_admin_can_add_a_problem_bank_entry_to_an_existing_contest()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        $bankItem = ProblemBank::create([
            'code' => 'NEWPROB',
            'name' => 'Brand New Problem',
            'description' => 'desc',
            'input_description' => 'in',
            'output_description' => 'out',
            'sample_input' => "1\n",
            'sample_output' => "1\n",
            'time_limit' => 1,
            'memory_limit' => 256,
            'difficulty' => 'easy',
            'is_active' => true,
        ]);

        $this->assertCount(0, Problem::where('contest_id', $contest->id)->get());

        $response = $this->actingAs($admin)->post('/backend/exercises', [
            'contest_id' => $contest->id,
            'problems' => [$bankItem->id],
        ]);

        $response->assertRedirect();

        $problem = Problem::where('contest_id', $contest->id)->firstOrFail();
        $this->assertSame('Brand New Problem', $problem->name);
        $this->assertCount(1, $problem->testCases);
        $this->assertTrue($problem->testCases->first()->is_sample);
    }

    public function test_adding_the_same_bank_entry_twice_does_not_duplicate_the_problem()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        $bankItem = ProblemBank::create([
            'code' => 'DUPCHECK',
            'name' => 'Duplicate Check',
            'description' => 'desc',
            'input_description' => 'in',
            'output_description' => 'out',
            'sample_input' => "1\n",
            'sample_output' => "1\n",
            'time_limit' => 1,
            'memory_limit' => 256,
            'difficulty' => 'easy',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post('/backend/exercises', [
            'contest_id' => $contest->id,
            'problems' => [$bankItem->id],
        ]);

        // Same bank item added again (e.g. a resubmitted form) must not
        // create a second Problem row or blow up on the unique constraint.
        $this->actingAs($admin)->post('/backend/exercises', [
            'contest_id' => $contest->id,
            'problems' => [$bankItem->id],
        ])->assertRedirect();

        $this->assertCount(1, Problem::where('contest_id', $contest->id)->get());
    }

    public function test_available_bank_items_excludes_problems_already_added_to_the_contest()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        $bankItem = ProblemBank::create([
            'code' => 'ALREADYIN',
            'name' => 'Already In Contest',
            'description' => 'desc',
            'input_description' => 'in',
            'output_description' => 'out',
            'sample_input' => "1\n",
            'sample_output' => "1\n",
            'time_limit' => 1,
            'memory_limit' => 256,
            'difficulty' => 'easy',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post('/backend/exercises', [
            'contest_id' => $contest->id,
            'problems' => [$bankItem->id],
        ]);

        $response = $this->actingAs($admin)->get('/backend/exercises?contest_id=' . $contest->id);

        $response->assertViewHas('availableBankItems', function ($items) {
            return $items->isEmpty();
        });
    }
}
