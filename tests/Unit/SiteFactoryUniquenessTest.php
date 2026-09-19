<?php

namespace Tests\Unit;

use App\Models\Contest;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `sites` tem `unique(['contest_id', 'name'])`, e a factory precisa respeitar
 * isso sozinha.
 *
 * Isto existe porque o CI quebrou de verdade: `ScoreboardSiteVisibilityTest`
 * falhou com `UNIQUE constraint failed: sites.contest_id, sites.name` e o nome
 * repetido "Harvey LLC", num PR que não tocava em site nenhum -- só tinha
 * acrescentado testes, o que aumentou o número de sorteios do Faker.
 *
 * Esse é o pior formato de falha para uma suíte: vermelho que não corresponde a
 * defeito, num arquivo que quem abriu o PR não mexeu. Ensina a equipe a
 * reexecutar o CI em vez de ler o vermelho -- e aí o próximo vermelho de
 * verdade também é reexecutado.
 */
class SiteFactoryUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_many_sites_in_the_same_contest_never_collide_by_name()
    {
        $contest = Contest::factory()->create();

        // 300 sorteios no mesmo contest. Com `faker->company` puro o catálogo
        // é de algumas centenas de combinações, então a colisão é praticamente
        // certa aqui -- é exatamente essa a mutação que prova o teste.
        Site::factory()->count(300)->create(['contest_id' => $contest->id]);

        $nomes = Site::where('contest_id', $contest->id)->pluck('name');

        $this->assertCount(300, $nomes);
        $this->assertSame(
            300,
            $nomes->unique()->count(),
            'a factory de Site gerou nome repetido dentro do mesmo contest'
        );
    }
}
