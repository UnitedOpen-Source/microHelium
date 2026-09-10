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
     *
     * @return void
     */
    public function test_exercise_index_page_loads_and_displays_exercises()
    {
        // 1. Arrange
        $contest = Contest::factory()->create(['is_active' => true]);
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
        $contest = Contest::factory()->create(['is_active' => true]);
        $problem = Problem::factory()->create([
            'contest_id' => $contest->id,
            'name' => 'Detailed Problem',
        ]);

        // 2. Act
        $response = $this->get('/exercise/' . $problem->id);

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
}
