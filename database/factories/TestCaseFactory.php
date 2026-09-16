<?php

namespace Database\Factories;

use App\Models\Problem;
use App\Models\TestCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestCase>
 */
class TestCaseFactory extends Factory
{
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'problem_id' => Problem::factory(),
            // Issue #228 -- era unique()->numberBetween(1, 100): CEM valores
            // para o processo inteiro, o teto mais apertado do repositorio.
            // Um problema com muitos casos, ou uma suite que cresca, esgota
            // o gerador -- e a excecao aparece longe da causa, dentro do
            // factory, num teste sobre outra coisa. Foi assim que o teto de
            // ProblemFactory apareceu num teste de autorizacao de rotas.
            'number' => ++self::$sequence,
            'input_file' => $this->faker->filePath(),
            'output_file' => $this->faker->filePath(),
            'input_hash' => $this->faker->sha256,
            'output_hash' => $this->faker->sha256,
            // Determinístico: `is_sample` decide se o caso e mostrado a
            // equipe, e um sorteio aqui e um teste de divulgacao que passa
            // metade das vezes. Quem quer o outro caso diz ->sample().
            'is_sample' => false,
        ];
    }

    public function sample(): static
    {
        return $this->state(fn () => ['is_sample' => true]);
    }
}
