<?php

namespace Database\Factories;

use App\Models\Contest;
use App\Models\Problem;
use App\Models\Score;
use Helium\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Score>
 */
class ScoreFactory extends Factory
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
            'problem_id' => Problem::factory(),
            'user_id' => User::factory(),
            // Issue #228 -- `attempts` sorteado nao tinha relacao com os
            // runs que existem, e `is_solved` sorteado decide se a celula
            // conta no placar. Uma celula tentada e nao resolvida e o estado
            // neutro; ->solved() diz o contrario.
            'attempts' => 1,
            'is_solved' => false,
            'is_first_solver' => false,
            'solved_time' => null,
            'penalty_time' => 0,
        ];
    }

    public function solved(int $atMinute = 30): static
    {
        return $this->state(fn () => ['is_solved' => true, 'solved_time' => $atMinute]);
    }
}
