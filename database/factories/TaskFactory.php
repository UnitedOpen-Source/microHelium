<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Contest;
use App\Models\Site;
use Helium\User;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Task>
 */
class TaskFactory extends Factory
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
            'site_id' => Site::factory(),
            'user_id' => User::factory(),
            'task_number' => $this->faker->unique()->numberBetween(1, 1000),
            'description' => $this->faker->sentence,
            'contest_time' => $this->faker->numberBetween(0, 3600),
            'status' => 'pending',
        ];
    }
}