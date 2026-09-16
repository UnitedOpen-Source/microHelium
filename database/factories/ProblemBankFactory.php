<?php

namespace Database\Factories;

use App\Models\ProblemBank;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProblemBank>
 */
class ProblemBankFactory extends Factory
{
    protected $model = ProblemBank::class;

    /**
     * Conta para a vida do processo, como Tests\TestCase::uniqueSuffix() e
     * ProblemFactory.
     *
     * `problem_bank.code` e unique. A fixture que ja existia no repositorio
     * (ProblemBankPolicyTest::bankAttributes()) sorteia com
     * `rand(10000, 99999)` -- 90 mil valores, entao a colisao e rara e nao
     * impossivel, que e exatamente a forma que as duas correcoes de
     * AnswerFactory tiveram: um flake que ninguem consegue atribuir. Uma
     * sequencia nao colide nunca, e custa o mesmo.
     */
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = ++self::$sequence;

        return [
            'code' => 'BANK'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'name' => 'Problema de banco '.$n,
            'description' => $this->faker->sentence(),
            'input_description' => 'Uma linha com um inteiro.',
            'output_description' => 'Um inteiro.',
            'difficulty' => 'easy',
            'tags' => [],
            'is_active' => true,
            // Explicito, e nao deixado para o default da coluna: Eloquent
            // nao le defaults do banco de volta, entao o modelo recem-criado
            // ficaria com `version` NULL em memoria. Um teste que mandasse
            // `(string) $bank->version` enviaria string vazia, que o
            // ConvertEmptyStringsToNull transforma em null, e o endpoint
            // responderia "Versao ausente" -- uma falha que parece do
            // produto e e da fixture.
            'version' => 1,
            'owning_org_id' => null,
        ];
    }
}
