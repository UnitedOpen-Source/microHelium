<?php

namespace Database\Factories;

use App\Models\Contest;
use App\Models\Leaderboard;
use Helium\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Leaderboard>
 */
class LeaderboardFactory extends Factory
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
            'user_id' => User::factory(),
            // Issue #228 -- os tres eram sorteados INDEPENDENTEMENTE, entao
            // nada garantia que `rank` fosse coerente com os outros dois: um
            // teste de ordenacao usando o factory estava afirmando algo sobre
            // dados que nao poderiam existir. Zerados e coerentes; quem quer
            // uma linha com pontuacao diz quanto.
            'problems_solved' => 0,
            'total_time' => 0,
            'rank' => 1,
        ];
    }
}
