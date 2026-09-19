<?php

namespace Database\Factories;

use App\Models\Contest;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    /**
     * Contador de processo, para o nome nao colidir.
     *
     * `sites` tem `unique(['contest_id', 'name'])`, e o `faker->company` sorteia
     * de um catalogo finito -- algumas centenas de combinacoes. Numa suite que
     * cria site em quase todo teste, duas chamadas acabam sorteando o mesmo
     * nome para o mesmo contest, e o insert morre com
     * `UNIQUE constraint failed: sites.contest_id, sites.name`.
     *
     * Aconteceu no CI: `ScoreboardSiteVisibilityTest` quebrou com o nome
     * "Harvey LLC" repetido, num PR que nao tocava em site nenhum -- so tinha
     * acrescentado testes, o que aumentou o numero de sorteios.
     *
     * O modo de falha e o pior possivel para uma suite: vermelho que nao
     * corresponde a defeito nenhum, num arquivo que quem abriu o PR nao mexeu.
     * Isso ensina a equipe a reexecutar o CI em vez de ler o vermelho.
     *
     * `faker->unique()` nao serve aqui: ele lanca `OverflowException` quando o
     * catalogo se esgota, e com mais de mil testes ele se esgota.
     */
    private static int $sequencia = 0;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company().' '.(++self::$sequencia),
            'contest_id' => Contest::factory(),
        ];
    }
}
