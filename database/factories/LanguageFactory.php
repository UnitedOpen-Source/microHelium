<?php

namespace Database\Factories;

use App\Models\Language;
use App\Models\Contest;
use Illuminate\Database\Eloquent\Factories\Factory;

class LanguageFactory extends Factory
{
    protected $model = Language::class;

    public function definition(): array
    {
        return [
            'contest_id' => Contest::factory(),
            'name' => $this->faker->randomElement(['C', 'C++', 'Java', 'Python', 'JavaScript']),
            'extension' => $this->faker->unique()->word(),
            'compile_command' => $this->faker->sentence(),
            'run_command' => $this->faker->sentence(),
            'is_active' => $this->faker->boolean(70),
        ];
    }
}
