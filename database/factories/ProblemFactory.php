<?php

namespace Database\Factories;

use App\Models\Contest;
use App\Models\Problem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Problem>
 */
class ProblemFactory extends Factory
{
    /**
     * Conta para a vida do processo, como Tests\TestCase::uniqueSuffix().
     *
     * `short_name` era `faker->unique()->lexify('?')`: UMA letra, ou seja 26
     * valores possiveis no total. Passado o vigesimo sexto problema de um
     * mesmo processo de teste, o gerador unico do faker desiste e lanca
     * "Maximum retries of 10000 reached without finding a unique value" --
     * nao e flake, e um teto.
     *
     * Bateu quando o #192 acrescentou seis rotas a
     * ApiRouteAuthorizationTest, cujo resolve() cria um problema por rota
     * percorrida. A falha aparece longe da causa: uma OverflowException
     * dentro do factory, num teste sobre autorizacao de rotas.
     *
     * Mesma familia dos dois consertos de AnswerFactory, e a licao e a
     * mesma: aleatorio num espaco pequeno nao e unico, e a coluna que diz
     * UNIQUE cobra isso.
     */
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->sentence(3);

        return [
            'contest_id' => Contest::factory(),
            'short_name' => self::nextShortName(),
            'name' => $name,
            'basename' => Str::slug($name),
        ];
    }

    /**
     * A, B, ... Z, AA, AB, ... -- como as letras de problema de uma prova de
     * verdade, e sem teto. Cabe nos 10 caracteres da coluna ate muito depois
     * de qualquer suite plausivel.
     */
    private static function nextShortName(): string
    {
        $index = self::$sequence++;
        $name = '';

        do {
            $name = chr(65 + $index % 26).$name;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $name;
    }
}
