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

    /**
     * Regression test for a bug the automated code review found: deriving
     * short_name from the queried bank collection's array index (rather
     * than from problems actually inserted) let a skipped duplicate leave a
     * gap, which a later call's independently-computed starting point could
     * then reuse, colliding with an existing short_name and throwing on the
     * unique constraint.
     */
    public function test_adding_problems_across_multiple_calls_never_collides_on_short_name()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();

        $make = fn (string $code) => ProblemBank::create([
            'code' => $code,
            'name' => $code,
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
        $p1 = $make('P1');
        $p2 = $make('P2');
        $p3 = $make('P3');
        $p4 = $make('P4');

        // Call 1: add P1, P2 -> A, B.
        $this->actingAs($admin)->post('/backend/exercises', [
            'contest_id' => $contest->id,
            'problems' => [$p1->id, $p2->id],
        ])->assertRedirect();

        // Call 2: resubmit P1 (already added, skipped) alongside new P3 ->
        // P3 must get C, not skip to D.
        $this->actingAs($admin)->post('/backend/exercises', [
            'contest_id' => $contest->id,
            'problems' => [$p1->id, $p3->id],
        ])->assertRedirect();

        // Call 3: add P4 alone -> must get D, and must not collide with
        // whatever letter P3 actually received.
        $this->actingAs($admin)->post('/backend/exercises', [
            'contest_id' => $contest->id,
            'problems' => [$p4->id],
        ])->assertRedirect(); // would 500 on the pre-fix bug

        $shortNames = Problem::where('contest_id', $contest->id)->pluck('short_name');
        $this->assertCount(4, $shortNames);
        $this->assertCount(4, $shortNames->unique(), 'no two problems in the same contest may share a short_name');
        $this->assertEqualsCanonicalizing(['A', 'B', 'C', 'D'], $shortNames->all());
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
