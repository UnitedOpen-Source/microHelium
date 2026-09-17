<?php

namespace Tests\Unit;

use App\Models\Contest;
use Tests\TestCase;

/**
 * Issue #213 -- o factory nao pode sortear o estado da prova.
 *
 * `start_time` era `dateTimeBetween('-1 month', '+1 month')`: metade dos
 * sorteios produzia um contest CONGELADO -- medido, 102 em 200. Qualquer
 * teste que usasse o factory sem fixar o tempo perguntava a moeda se estava
 * testando uma prova rodando, congelada ou nem comecada.
 *
 * Isso ja tinha acontecido neste mesmo arquivo com `is_public`, e o
 * comentario do #135 registra: o sorteio ficou invisivel ate a listagem
 * comecar a honrar a flag. Com o tempo foi igual -- enquanto o congelamento
 * nao fazia nada (#211), a moeda nunca aparecia.
 *
 * Este teste e a guarda contra a terceira vez. Ele nao testa o produto;
 * testa que o fixture nao decide o assunto do teste por sorteio.
 */
class ContestFactoryDeterminismTest extends TestCase
{
    public function test_the_default_contest_is_running_and_not_frozen(): void
    {
        // O relogio fica parado durante o laco -- issue #250.
        //
        // `start_time` do factory e `now()`, e `getContestTime()` devolve
        // SEGUNDOS decorridos desde ele. Sem congelar, `assertSame(0, ...)`
        // so vale enquanto o `create()` e a assercao couberem no mesmo
        // segundo de relogio de parede: um INSERT lento, um GC ou um runner
        // disputado fazem `diffInSeconds` devolver 1.0x, o cast truncar para
        // 1, e o teste cair. Foi o que derrubou o CI do master em a8df411,
        // com a mensagem exata "Failed asserting that 1 is identical to 0".
        //
        // Congelar nao afrouxa a guarda -- afrouxar seria trocar o `0` por
        // um `assertLessThan`, que reabriria a janela de sorteio que a #213
        // fechou. Com o tempo parado, "o relogio comecou no zero" volta a
        // ser uma afirmacao sobre o FACTORY em vez de uma corrida contra o
        // relogio de parede: um `start_time` sorteado continua caindo aqui.
        $this->freezeTime(function () {
            // Muitos, e nao um: um sorteio que acerta uma vez nao prova nada,
            // e o defeito original passava metade das vezes.
            for ($i = 0; $i < 25; $i++) {
                $contest = Contest::factory()->create();

                $this->assertTrue($contest->isRunning(), 'o contest padrao deveria estar rodando');
                $this->assertFalse($contest->isFrozen(), 'o contest padrao caiu na janela de congelamento');
                $this->assertSame(0, $contest->getContestTime(), 'o relogio do contest padrao nao comecou no zero');
            }
        });
    }

    public function test_the_named_states_say_what_they_are(): void
    {
        $this->assertTrue(Contest::factory()->frozen()->create()->isFrozen());
        $this->assertFalse(Contest::factory()->notStarted()->create()->isRunning());
        $this->assertFalse(Contest::factory()->finished()->create()->isRunning());
        $this->assertTrue(Contest::factory()->running(45)->create()->isRunning());
    }

    /**
     * A prova que acabou continua congelada ate alguem descongelar -- e a
     * cerimonia do #189, e um estado nomeado que mentisse sobre isso
     * esconderia justamente o comportamento que aquela issue instalou.
     */
    public function test_a_finished_contest_is_still_frozen_until_someone_unfreezes_it(): void
    {
        $contest = Contest::factory()->finished()->create();

        $this->assertTrue($contest->isFrozen());

        $contest->update(['unfrozen_at' => now()]);

        $this->assertFalse($contest->fresh()->isFrozen());
    }
}
