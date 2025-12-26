<?php

namespace Database\Factories;

use App\Models\Run;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Site;
use Helium\User;
use App\Models\Problem;
use Illuminate\Database\Eloquent\Factories\Factory;

class RunFactory extends Factory
{
    protected $model = Run::class;

    public function definition(): array
    {
        return [
            'contest_id' => Contest::factory(),
            'site_id' => Site::factory(),
            'user_id' => User::factory(),
            'problem_id' => Problem::factory(),
            'language_id' => Language::factory(),
            'run_number' => $this->faker->unique()->numberBetween(1, 1000),
            'filename' => $this->faker->word() . '.txt',
            'source_file' => 'runs/' . $this->faker->uuid() . '.txt',
            'source_hash' => $this->faker->sha256(),
            'contest_time' => $this->faker->numberBetween(0, 18000),
            'status' => $this->faker->randomElement(['pending', 'judged', 'judging']),
        ];
    }
}