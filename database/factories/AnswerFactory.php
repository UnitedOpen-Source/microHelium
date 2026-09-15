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
            // Unique, not merely random. `answers` carries TWO unique
            // indexes -- (contest_id, name) and (contest_id, short_name) --
            // and a test that creates several answers in one contest
            // (SimilarityTest::acceptedRun() does, once per run) collides
            // on either of them often enough to fail intermittently.
            //
            // short_name was fixed first, after two random letters (26^2)
            // produced a CI flake nobody could attribute. `name` was left
            // on faker->words(2, true) and kept the same defect on the
            // other column: it failed a full run while #189 was being
            // built, with "UNIQUE constraint failed: answers.contest_id,
            // answers.name" on the word pair "in sit". Fixing one of two
            // unique columns is fixing half a flake.
            'name' => $this->faker->unique()->words(2, true).' '.$this->faker->unique()->numerify('###'),
            'short_name' => strtoupper($this->faker->unique()->lexify('???')),
            'is_accepted' => false,
        ];
    }
}
