<?php

namespace Database\Factories;

use App\Models\Contest;
use App\Models\Language;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Language>
 */
class LanguageFactory extends Factory
{
    protected $model = Language::class;

    /**
     * Conta para a vida do processo. Mesma razao de ProblemFactory e
     * ProblemBankFactory: `faker->unique()->word()` tira de uma lista finita
     * e acaba, e quando acaba a excecao aparece longe da causa.
     */
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = ++self::$sequence;

        return [
            'contest_id' => Contest::factory(),
            'name' => 'Linguagem '.$n,
            'extension' => 'l'.$n,
            'compile_command' => $this->faker->sentence(),
            'run_command' => $this->faker->sentence(),

            // Issue #213, terceira ocorrencia do mesmo padrao.
            //
            // Era `faker->boolean(70)`, numa coluna que DECIDE SE DA PARA
            // SUBMETER: trinta por cento das linguagens de teste nasciam
            // inativas, e qualquer teste que enviasse um run com ela falhava
            // por sorteio. Foi assim que este arquivo entrou na issue #198 --
            // um teste de extensao de tempo falhava um em cada tres, e a
            // causa nao estava no relogio.
            //
            // O mesmo comentario ja existe em ContestFactory para start_time
            // e em ContestFactory/#135 para is_public. Quem quer o outro
            // caso agora diz: ->inactive().
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
