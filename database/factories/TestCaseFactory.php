<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Problem;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TestCase>
 */
class TestCaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'problem_id' => Problem::factory(),
            'number' => $this->faker->unique()->numberBetween(1, 100),
            'input_file' => $this->faker->filePath(),
            'output_file' => $this->faker->filePath(),
            'input_hash' => $this->faker->sha256,
            'output_hash' => $this->faker->sha256,
            'is_sample' => $this->faker->boolean,
        ];
    }
}