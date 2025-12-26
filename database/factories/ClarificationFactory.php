<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Contest;
use App\Models\Site;
use Helium\User;
use App\Models\Problem;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Clarification>
 */
class ClarificationFactory extends Factory
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
            'problem_id' => Problem::factory(),
            'clarification_number' => $this->faker->unique()->numberBetween(1, 1000),
            'question' => $this->faker->sentence,
            'answer' => null,
            'contest_time' => $this->faker->numberBetween(0, 3600),
            'answered_time' => null,
            'status' => 'pending',
            'judge_id' => null,
            'judge_site_id' => null,
        ];
    }
}