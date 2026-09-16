<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\ContestTimeAdjustment;
use App\Models\Leaderboard;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Score;
use App\Models\Site;
use App\Services\ContestClock;
use App\Services\ContestFinalizer;
use App\Services\ContestLifecycle;
use Helium\User;
use Tests\TestCase;

/**
 * Issue #225 -- os atalhos legados faziam o OPOSTO do que prometiam.
 *
 * Medido ponta a ponta antes de consertar, com um solve dentro da janela de
 * congelamento:
 *
 *   [1] congelado, placar esconde o solve
 *   [2] depois de POST /contest/freeze -> freeze_time=0, NAO congelado,
 *       placar MOSTRA o solve
 *   [3] depois de POST /contest/end -> is_active=false, NAO congelado,
 *       placar MOSTRA o solve
 *   [4] preflight de prova que nao comecou: []
 *
 * Os dois botoes publicavam a classificacao. O primeiro chamava-se "Placar
 * congelado!".
 *
 * A regressao que estes testes fixam e a sequencia que a issue pede:
 * congelar -> terminar -> placar ainda oculto -> revelar -> finalizar.
 */
class ContestLifecycleTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    private Problem $problem;

    private Answer $yes;

    private User $team;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Prova correndo ha 100 min de 300, congelamento de 60 -- longe do
        // corte, para que o congelamento so aconteca se alguem mandar.
        $this->contest = Contest::factory()->running(100)->create(['is_public' => true]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problem = Problem::factory()->create(['contest_id' => $this->contest->id]);
        $this->yes = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'YES', 'is_accepted' => true]);
        $this->team = $this->createTestUser(['contest_id' => $this->contest->id, 'site_id' => $this->site->id]);
        $this->admin = $this->createTestUser(['user_type' => 'admin']);
    }

    private function solveAt(int $minute): void
    {
        $run = Run::factory()->judged($this->yes)->at($minute)->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $this->team->user_id,
            'problem_id' => $this->problem->id,
        ]);

        Score::updateScore($run->fresh());
    }

    private function publicBoardShowsTheSolve(): bool
    {
        $contest = $this->contest->fresh();
        $row = collect(Leaderboard::getScoreboard($contest->id, $contest->isFrozen()))
            ->firstWhere('user.user_id', $this->team->user_id);

        return (bool) ($row['problems'][0]['is_solved'] ?? false);
    }

    // -- congelar agora -----------------------------------------------------

    /**
     * O botao escrito "Placar congelado!" gravava `freeze_time = 0`, e o
     * #189 le zero como "sem congelamento nenhum".
     */
    public function test_freezing_now_actually_freezes(): void
    {
        $this->assertFalse($this->contest->isFrozen(), 'o cenario comeca descongelado');

        app(ContestLifecycle::class)->freezeNow($this->contest, $this->admin);

        $this->assertTrue($this->contest->fresh()->isFrozen());
        $this->assertGreaterThan(0, (int) $this->contest->fresh()->getAttributes()['freeze_time'], 'zero significa "sem congelamento"');
    }

    public function test_freezing_now_hides_a_solve_made_after_it(): void
    {
        app(ContestLifecycle::class)->freezeNow($this->contest, $this->admin);

        $this->solveAt(120);

        $this->assertFalse($this->publicBoardShowsTheSolve());
    }

    public function test_the_web_shortcut_freezes_instead_of_publishing(): void
    {
        $this->actingAs($this->admin)->post('/backend/contest/freeze')->assertRedirect();

        $this->assertTrue($this->contest->fresh()->isFrozen());
    }

    // -- encerrar mais cedo -------------------------------------------------

    /**
     * O atalho gravava `is_active = false`, e isFrozen() devolvia falso para
     * contest inativo -- entao encerrar publicava a classificacao.
     */
    public function test_ending_early_keeps_the_board_frozen(): void
    {
        app(ContestLifecycle::class)->freezeNow($this->contest, $this->admin);
        $this->solveAt(120);

        app(ContestLifecycle::class)->endEarly($this->contest->fresh(), $this->admin);

        $this->assertFalse($this->contest->fresh()->isRunning(), 'a prova tem que ter acabado');
        $this->assertTrue($this->contest->fresh()->isFrozen(), 'e o placar tem que continuar congelado');
        $this->assertFalse($this->publicBoardShowsTheSolve());
    }

    /**
     * Encerrar nao e desativar. O contest continua sendo o evento corrente
     * ate alguem trocar -- terminar e revelar sao decisoes separadas.
     */
    public function test_ending_early_does_not_deactivate_the_contest(): void
    {
        app(ContestLifecycle::class)->endEarly($this->contest, $this->admin);

        $this->assertTrue((bool) $this->contest->fresh()->is_active);
    }

    public function test_the_web_shortcut_ends_without_publishing(): void
    {
        app(ContestLifecycle::class)->freezeNow($this->contest, $this->admin);
        $this->solveAt(120);

        $this->actingAs($this->admin)->post('/backend/contest/end')->assertRedirect();

        $this->assertFalse($this->publicBoardShowsTheSolve());
    }

    /**
     * E o conserto mais fundo: desativar por qualquer caminho nao pode
     * descongelar. Estar ativo e "este e o evento corrente", e nao "a
     * classificacao ja foi liberada".
     */
    public function test_deactivating_a_contest_does_not_publish_its_standings(): void
    {
        app(ContestLifecycle::class)->freezeNow($this->contest, $this->admin);
        $this->solveAt(120);

        $this->contest->update(['is_active' => false]);

        $this->assertTrue($this->contest->fresh()->isFrozen());
        $this->assertFalse($this->publicBoardShowsTheSolve());
    }

    // -- a sequencia inteira ------------------------------------------------

    /**
     * A regressao que a issue pede: congelar -> terminar -> placar ainda
     * oculto -> revelar -> finalizar.
     */
    public function test_the_whole_ceremony_in_order(): void
    {
        $lifecycle = app(ContestLifecycle::class);

        $lifecycle->freezeNow($this->contest, $this->admin);
        $this->solveAt(120);
        $this->assertFalse($this->publicBoardShowsTheSolve(), 'congelou');

        $lifecycle->endEarly($this->contest->fresh(), $this->admin);
        $this->assertFalse($this->publicBoardShowsTheSolve(), 'terminou e continua oculto');

        // Finalizar antes de revelar e recusado (#202).
        $this->assertContains(
            'still_frozen',
            array_column(app(ContestFinalizer::class)->blockers($this->contest->fresh()), 'code')
        );

        $this->contest->update(['unfrozen_at' => now()]);
        $this->assertTrue($this->publicBoardShowsTheSolve(), 'revelou');

        $this->assertSame([], array_column(app(ContestFinalizer::class)->blockers($this->contest->fresh()), 'code'));
    }

    /**
     * Um contest sem congelamento configurado nao passa a ter um so porque
     * alguem encerrou mais cedo.
     */
    public function test_ending_a_contest_with_no_freeze_leaves_it_unfrozen(): void
    {
        $this->contest->update(['freeze_time' => 0]);
        $this->solveAt(50);

        app(ContestLifecycle::class)->endEarly($this->contest->fresh(), $this->admin);

        $this->assertFalse($this->contest->fresh()->isFrozen());
        $this->assertTrue($this->publicBoardShowsTheSolve());
    }

    /**
     * Encerrar agora tem que encerrar AGORA, mesmo com tempo removido.
     *
     * A duracao e medida em tempo QUE CONTA (#198): somar de volta os
     * minutos descontados faria a prova "acabar" depois do instante em que
     * se pediu que ela acabasse -- e ela continuaria aceitando envios. Uma
     * mutacao mostrou que sem este caso a subtracao podia ser apagada.
     */
    public function test_ending_early_ends_now_even_with_a_removed_interval(): void
    {
        ContestTimeAdjustment::create([
            'contest_id' => $this->contest->id,
            'site_id' => null,
            'starts_at' => $this->contest->start_time->copy()->addMinutes(20),
            'ends_at' => $this->contest->start_time->copy()->addMinutes(60),
            'reason' => 'queda de energia',
        ]);
        app(ContestClock::class)->forget();

        app(ContestLifecycle::class)->endEarly($this->contest->fresh(), $this->admin);
        app(ContestClock::class)->forget();

        $this->assertFalse(
            $this->contest->fresh()->isRunning(),
            'encerrar agora tem que encerrar agora, e nao daqui a quarenta minutos'
        );
    }

    /**
     * Issue #240 -- e tem que encerrar agora tambem quando o relogio nao
     * esta redondo.
     *
     * `duration` e em MINUTOS. Arredondando para cima, um encerramento
     * pedido a 100min01s virava 101 minutos de prova: `isRunning()` seguia
     * verdadeiro por mais 59 segundos e a prova continuava aceitando envios.
     *
     * O caso acima nao pegava isso por sorte de relogio -- passava quando a
     * maquina era rapida o bastante para chamar `endEarly()` dentro do mesmo
     * segundo em que a competicao foi criada, e falhava no CI quando nao
     * era. O `travel` abaixo tira a sorte da conta.
     */
    public function test_ending_early_ends_now_on_a_second_that_is_not_a_whole_minute(): void
    {
        $this->travel(1)->seconds();

        app(ContestLifecycle::class)->endEarly($this->contest->fresh(), $this->admin);
        app(ContestClock::class)->forget();

        $encerrada = $this->contest->fresh();

        $this->assertFalse(
            $encerrada->isRunning(),
            'um segundo quebrado nao pode comprar 59 segundos a mais de prova'
        );
        $this->assertTrue(
            $encerrada->end_time->lte(now()),
            'o fim gravado nao pode cair depois do instante em que se pediu o encerramento'
        );
    }

    /**
     * Abortar a prova no primeiro minuto encerra de verdade.
     *
     * O `max(1, ...)` que estava aqui punha o fim a um minuto no futuro
     * exatamente no caso em que mais importa parar: um problema errado no
     * ar, a prova recem-comecada e a organizacao mandando parar.
     */
    public function test_a_contest_aborted_in_its_first_minute_stops_immediately(): void
    {
        $recem = Contest::factory()->running(0)->create();
        $this->travel(30)->seconds();

        app(ContestLifecycle::class)->endEarly($recem->fresh(), $this->admin);
        app(ContestClock::class)->forget();

        $this->assertFalse($recem->fresh()->isRunning(), 'abortar no primeiro minuto tem que parar a prova');
    }

    // -- preflight ----------------------------------------------------------

    /**
     * Uma prova que nao comecou passava no preflight sem NENHUM impedimento:
     * isRunning() e falso para ela, e sem envio, sem erro de julgamento e sem
     * clarificacao -- o estado natural de quem nao aconteceu -- a lista saia
     * vazia.
     */
    public function test_a_contest_that_has_not_started_cannot_be_finalized(): void
    {
        $futuro = Contest::factory()->notStarted()->create();

        $this->assertContains(
            'contest_not_started',
            array_column(app(ContestFinalizer::class)->blockers($futuro), 'code')
        );
    }

    public function test_a_contest_with_no_start_time_cannot_be_finalized(): void
    {
        $semHorario = Contest::factory()->create(['start_time' => null]);

        $this->assertContains(
            'contest_not_scheduled',
            array_column(app(ContestFinalizer::class)->blockers($semHorario), 'code')
        );
    }
}
