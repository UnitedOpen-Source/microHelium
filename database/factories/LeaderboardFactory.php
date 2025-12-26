<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Contest;
use Helium\User;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Leaderboard>
 */
class LeaderboardFactory extends Factory
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
            'user_id' => User::factory(),
            'problems_solved' => $this->faker->numberBetween(0, 10),
            'total_time' => $this->faker->numberBetween(0, 1000),
            'rank' => $this->faker->numberBetween(1, 100),
        ];
    }
}