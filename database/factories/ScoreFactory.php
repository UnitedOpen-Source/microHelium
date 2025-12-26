<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Contest;
use App\Models\Problem;
use Helium\User;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Score>
 */
class ScoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contest_id' => Contest::factory(),
            'problem_id' => Problem::factory(),
            'user_id' => User::factory(),
            'attempts' => $this->faker->numberBetween(1, 10),
            'is_solved' => $this->faker->boolean,
            'is_first_solver' => false,
            'solved_time' => null,
            'penalty_time' => 0,
        ];
    }
}