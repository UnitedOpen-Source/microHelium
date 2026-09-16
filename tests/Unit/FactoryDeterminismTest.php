<?php

namespace Tests\Unit;

use App\Models\Clarification;
use App\Models\ContestLog;
use App\Models\Leaderboard;
use App\Models\Run;
use App\Models\Score;
use App\Models\Task;
use App\Models\TestCase as ProblemTestCase;
use Tests\TestCase;

/**
 * Issue #228 -- a guarda contra a quarta ocorrencia.
 *
 * O padrao: uma factory sorteia uma coluna que uma regra de negocio
 * consulta, o teste passa ou falha por sorteio, e o sorteio fica invisivel
 * enquanto a regra nao faz nada -- aparece no dia em que o codigo comeca a
 * funcionar. Ja mordeu tres vezes:
 *
 *   #135  contests.is_public   tres testes passavam metade das vezes
 *   #213  contests.start_time  102 de 200 sorteios saiam congelados
 *   #198  languages.is_active  um teste falhava 1 em 3, e a causa nao
 *                              estava onde parecia
 *
 * Este teste nao testa o produto: ele testa que o FIXTURE nao decide o
 * assunto do teste por sorteio. E o mesmo formato da guarda que o #213
 * deixou para ContestFactory.
 *
 * Varios objetos e nao um: um sorteio que acerta uma vez nao prova nada, e
 * os defeitos originais passavam metade das vezes.
 */
class FactoryDeterminismTest extends TestCase
{
    private const REPETITIONS = 20;

    /**
     * `status` decide se o run conta para o placar, se esta pendente, e se o
     * watchdog do #45 o adota. `contest_time` decide, desde o #211, se o run
     * aparece no placar congelado.
     */
    public function test_a_run_is_born_pending_and_early(): void
    {
        for ($i = 0; $i < self::REPETITIONS; $i++) {
            $run = Run::factory()->create();

            $this->assertSame('pending', $run->status);
            $this->assertNull($run->answer_id);
            $this->assertSame(30 * 60, (int) $run->contest_time, 'o envio padrao tem que ficar longe de qualquer corte');
        }
    }

    public function test_the_named_run_states_say_what_they_are(): void
    {
        $this->assertSame('judging', Run::factory()->judging()->create()->status);
        $this->assertSame('judged', Run::factory()->judged()->create()->status);
        $this->assertSame(90 * 60, (int) Run::factory()->at(90)->create()->contest_time);
    }

    /**
     * Um run `judged` sem `judged_time` e um estado que nenhum caminho de
     * producao produz -- todos os finalizadores gravam os dois.
     */
    public function test_a_judged_run_carries_its_judged_time(): void
    {
        $run = Run::factory()->judged()->at(75)->create();

        $this->assertSame(75 * 60, (int) $run->contest_time);
        $this->assertSame(75 * 60, (int) $run->judged_time);
    }

    public function test_the_other_factories_do_not_roll_dice_on_decisions(): void
    {
        for ($i = 0; $i < self::REPETITIONS; $i++) {
            $this->assertFalse((bool) ProblemTestCase::factory()->create()->is_sample);
            $this->assertSame('info', ContestLog::factory()->create()->type);
            $this->assertFalse((bool) Score::factory()->create()->is_solved);

            $entry = Leaderboard::factory()->create();
            $this->assertSame(0, (int) $entry->problems_solved);
            $this->assertSame(0, (int) $entry->total_time);
        }
    }

    /**
     * Os contadores unicos nao podem ter teto.
     *
     * `TestCaseFactory.number` era unique()->numberBetween(1, 100) -- cem
     * valores para o processo inteiro. Criar mais do que isso levantava
     * "Maximum retries of 10000 reached", dentro do factory, num teste sobre
     * outra coisa. Cento e vinte aqui passa do antigo teto de proposito.
     */
    public function test_the_sequenced_columns_have_no_ceiling(): void
    {
        $numbers = [];

        for ($i = 0; $i < 120; $i++) {
            $numbers[] = (int) ProblemTestCase::factory()->create()->number;
        }

        $this->assertCount(120, array_unique($numbers), 'numeros de caso de teste repetiram');
    }

    public function test_run_numbers_do_not_collide_beyond_the_old_ceiling(): void
    {
        $numbers = [];

        for ($i = 0; $i < 60; $i++) {
            $numbers[] = (int) Run::factory()->create()->run_number;
        }

        $this->assertCount(60, array_unique($numbers));
    }

    public function test_clarification_and_task_numbers_are_sequenced(): void
    {
        $clarifications = [];
        $tasks = [];

        for ($i = 0; $i < 30; $i++) {
            $clarifications[] = (int) Clarification::factory()->create()->clarification_number;
            $tasks[] = (int) Task::factory()->create()->task_number;
        }

        $this->assertCount(30, array_unique($clarifications));
        $this->assertCount(30, array_unique($tasks));
    }
}
