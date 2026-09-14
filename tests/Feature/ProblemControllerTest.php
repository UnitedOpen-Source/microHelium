<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Problem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProblemControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test the problems index page loads correctly for the active contest.
     */
    /**
     * Issue #135 -- the listing and the detail page must agree.
     *
     * show() has always refused a guest a problem whose contest is not
     * public; index() listed the same problems to the same guest. is_public
     * defaults to false, so the listing was the more permissive of the two
     * by accident, and a running private contest published its problem list
     * to anyone who asked.
     */
    public function test_a_guest_is_not_shown_a_private_contests_problems(): void
    {
        $contest = Contest::factory()->create(['is_active' => true, 'is_public' => false]);
        $problem = Problem::factory()->create([
            'contest_id' => $contest->id, 'short_name' => 'A', 'name' => 'SEGREDO',
        ]);

        $response = $this->get('/exercises');

        $response->assertStatus(200);
        $response->assertViewHas('problems', fn ($problems) => count($problems) === 0);
        $response->assertDontSee('SEGREDO');

        // The detail page already behaved this way; this is the pair.
        $this->get("/exercise/{$problem->id}")->assertNotFound();
    }

    /**
     * And a team still sees its own contest, public or not -- the fix must
     * not lock competitors out of the competition they are in.
     */
    public function test_a_team_still_sees_its_own_private_contest(): void
    {
        $contest = Contest::factory()->create(['is_active' => true, 'is_public' => false]);
        Problem::factory()->create(['contest_id' => $contest->id, 'short_name' => 'A', 'name' => 'P1']);
        $team = $this->createTestUser(['contest_id' => $contest->id, 'user_type' => 'team']);

        $response = $this->actingAs($team)->get('/exercises');

        $response->assertStatus(200);
        $response->assertViewHas('problems', fn ($problems) => count($problems) === 1);
    }

    public function test_exercise_index_page_loads_and_displays_exercises()
    {
        // 1. Arrange
        // is_public explicit: ContestFactory draws it from faker->boolean(50),
        // and this test acts as a GUEST, who sees a contest's problems only
        // when it is public (#135). Left to chance it passed half the time.
        $contest = Contest::factory()->create(['is_active' => true, 'is_public' => true]);
        Problem::factory()->create(['contest_id' => $contest->id, 'short_name' => 'A', 'name' => 'P1']);
        Problem::factory()->create(['contest_id' => $contest->id, 'short_name' => 'B', 'name' => 'P2']);

        // 2. Act
        $response = $this->get('/exercises');

        // 3. Assert
        $response->assertStatus(200);
        $response->assertViewIs('exercises.index');
        $response->assertViewHas('problems', function ($problems) {
            return count($problems) === 2;
        });
        $response->assertSeeText('P1');
        $response->assertSeeText('P2');
    }

    /**
     * Test the problem show page loads for a valid problem.
     *
     * @return void
     */
    public function test_exercise_show_page_loads_for_valid_exercise()
    {
        // 1. Arrange
        $contest = Contest::factory()->create(['is_active' => true, 'is_public' => true]);
        $problem = Problem::factory()->create([
            'contest_id' => $contest->id,
            'name' => 'Detailed Problem',
        ]);

        // 2. Act
        $response = $this->get('/exercise/'.$problem->id);

        // 3. Assert
        $response->assertStatus(200);
        $response->assertViewIs('exercises.show');
        $response->assertViewHas('problem', function ($viewProblem) use ($problem) {
            return $viewProblem->id === $problem->id;
        });
        $response->assertSeeText('Detailed Problem');
    }

    /**
     * Test the problem show page returns 404 for an invalid problem.
     *
     * @return void
     */
    public function test_exercise_show_page_returns_404_for_invalid_exercise()
    {
        // 2. Act
        $response = $this->get('/exercise/9999');

        // 3. Assert
        $response->assertStatus(404);
    }

    /**
     * A problem belonging to a different, private contest than the one the
     * viewer would see in the /exercises list must not be viewable just by
     * guessing its numeric id -- ProblemController::show() had no
     * authorization check at all before this.
     */
    public function test_exercise_show_page_returns_404_for_a_problem_from_another_private_contest()
    {
        // The contest the anonymous viewer would actually see via resolveContest()
        Contest::factory()->create(['is_active' => true, 'is_public' => true]);

        $otherContest = Contest::factory()->create(['is_active' => false, 'is_public' => false]);
        $otherProblem = Problem::factory()->create(['contest_id' => $otherContest->id]);

        $response = $this->get('/exercise/'.$otherProblem->id);

        $response->assertStatus(404);
    }

    public function test_exercise_show_page_is_visible_for_a_problem_from_a_public_contest_even_if_not_current()
    {
        Contest::factory()->create(['is_active' => true, 'is_public' => true]);

        $publicOldContest = Contest::factory()->create(['is_active' => false, 'is_public' => true]);
        $problem = Problem::factory()->create(['contest_id' => $publicOldContest->id, 'name' => 'Old Public Problem']);

        $response = $this->get('/exercise/'.$problem->id);

        $response->assertStatus(200);
        $response->assertSeeText('Old Public Problem');
    }
}
