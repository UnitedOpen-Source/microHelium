<?php

namespace Database\Factories;

use App\Models\Contest;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContestFactory extends Factory
{
    protected $model = Contest::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'start_time' => $this->faker->dateTimeBetween('-1 month', '+1 month'),
            'duration' => $this->faker->numberBetween(60, 300), // 1 to 5 hours in minutes
            'freeze_time' => $this->faker->numberBetween(30, 60),
            'penalty' => $this->faker->numberBetween(10, 30),
            'max_file_size' => $this->faker->numberBetween(100, 1000),
            'is_active' => $this->faker->boolean(70),
            'is_public' => $this->faker->boolean(50),
            'unlock_key' => $this->faker->optional()->word(),
        ];
    }
}
