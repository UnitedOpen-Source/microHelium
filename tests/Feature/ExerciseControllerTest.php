<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExerciseControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test the exercise index page loads correctly.
     *
     * @return void
     */
    public function test_exercise_index_page_loads_and_displays_exercises()
    {
        // 1. Arrange
        DB::table('exercises')->insert([
            ['exerciseName' => 'P1', 'difficulty' => 'easy', 'score' => 100, 'expectedOutcome' => 'outcome1'],
            ['exerciseName' => 'P2', 'difficulty' => 'medium', 'score' => 200, 'expectedOutcome' => 'outcome2'],
        ]);

        // 2. Act
        $response = $this->get('/exercises');

        // 3. Assert
        $response->assertStatus(200);
        $response->assertViewIs('exercises.index');
        $response->assertViewHas('exercises', function ($exercises) {
            return count($exercises) === 2;
        });
        $response->assertSeeText('P1');
        $response->assertSeeText('P2');
    }

    /**
     * Test the exercise show page loads for a valid exercise.
     *
     * @return void
     */
    public function test_exercise_show_page_loads_for_valid_exercise()
    {
        // 1. Arrange
        $exerciseId = DB::table('exercises')->insertGetId(
            ['exerciseName' => 'Detailed Problem', 'difficulty' => 'hard', 'score' => 300, 'expectedOutcome' => 'outcome3']
        );

        // 2. Act
        $response = $this->get('/exercise/' . $exerciseId);

        // 3. Assert
        $response->assertStatus(200);
        $response->assertViewIs('exercises.show');
        $response->assertViewHas('exercise', function ($exercise) use ($exerciseId) {
            return $exercise->exercise_id === $exerciseId;
        });
        $response->assertSeeText('Detailed Problem');
    }

    /**
     * Test the exercise show page returns 404 for an invalid exercise.
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
