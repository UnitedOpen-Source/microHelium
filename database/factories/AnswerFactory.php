<?php

namespace Database\Factories;

use App\Models\Contest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Answer>
 */
class AnswerFactory extends Factory
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
            'name' => $this->faker->words(2, true),
            // Unique, not merely random: answers are unique on
            // (contest_id, short_name), and a test that creates several
            // answers in one contest -- SimilarityTest::acceptedRun() does,
            // once per run -- collided often enough on two random letters
            // (26^2) to fail CI intermittently. faker's unique() guarantees
            // no repeat for the lifetime of the test run.
            'short_name' => strtoupper($this->faker->unique()->lexify('???')),
            'is_accepted' => false,
        ];
    }
}
