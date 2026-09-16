<?php

namespace Database\Factories;

use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Site;
use Helium\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContestLog>
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
            // Issue #228 -- era randomElement entre tres severidades. Um
            // teste que filtre por severidade contra um factory que sorteia
            // e uma moeda.
            'type' => 'info',
            'message' => $this->faker->sentence,
            'context' => null,
        ];
    }

    public function warning(): static
    {
        return $this->state(fn () => ['type' => 'warning']);
    }

    public function error(): static
    {
        return $this->state(fn () => ['type' => 'error']);
    }
}
