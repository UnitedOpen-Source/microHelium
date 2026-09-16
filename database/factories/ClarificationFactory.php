<?php

namespace Database\Factories;

use App\Models\Clarification;
use App\Models\Contest;
use App\Models\Problem;
use App\Models\Site;
use Helium\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Clarification>
 */
class ClarificationFactory extends Factory
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
            'problem_id' => Problem::factory(),
            // Issue #228 -- sequencia em vez de unique()->numberBetween.
            'clarification_number' => ++self::$sequence,
            'question' => $this->faker->sentence,
            'answer' => null,
            'contest_time' => 30 * 60,
            'answered_time' => null,
            'status' => 'pending',
            'judge_id' => null,
            'judge_site_id' => null,
        ];
    }
}
