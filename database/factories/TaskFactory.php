<?php

namespace Database\Factories;

use App\Models\Contest;
use App\Models\Site;
use App\Models\Task;
use Helium\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contest_id' => Contest::factory(),
            'site_id' => Site::factory(),
            'user_id' => User::factory(),
            // Issue #228 -- sequencia em vez de unique()->numberBetween,
            // que tinha teto de mil valores.
            'task_number' => ++self::$sequence,
            'description' => $this->faker->sentence,
            'contest_time' => 30 * 60,
            'status' => 'pending',
        ];
    }
}
