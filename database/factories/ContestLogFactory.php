<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Contest;
use App\Models\Site;
use Helium\User;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ContestLog>
 */
class ContestLogFactory extends Factory
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
            'ip_address' => $this->faker->ipv4,
            'type' => $this->faker->randomElement(['info', 'warning', 'error']),
            'message' => $this->faker->sentence,
            'context' => null,
        ];
    }
}