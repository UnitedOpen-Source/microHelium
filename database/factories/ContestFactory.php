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
            // Issue #135: was faker->boolean(50). is_public gates who may
            // see a contest's problems, so randomising it made any test that
            // acts as a guest pass about half the time -- three of them did,
            // and only showed it once the listing started honouring the flag.
            // Matches the column's own default; a test that wants a public
            // contest now says so.
            'is_public' => false,
            'unlock_key' => $this->faker->optional()->word(),
        ];
    }
}
