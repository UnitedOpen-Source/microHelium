<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\ContestTimeAdjustment;
use App\Services\ContestClock;
use App\Services\ContestFinalizer;
use App\Services\ContestLifecycle;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Issue #223 -- o relogio da interface precisa perguntar ao servidor.
 *
 * Ele calculava "acabou" e "congelado" com o relogio LOCAL, e escondia o
 * aviso de congelamento depois do fim -- enquanto o servidor mantinha o
 * placar congelado (#189). Os dois discordavam na hora exata em que a
 * discordancia custa caro: a cerimonia.
 *
 * O trabalho de interface fica com quem cuida do frontend. O que estes
 * testes fixam e o CONTRATO do lado do servidor, para que a interface possa
 * parar de adivinhar.
 */
class CurrentContestStateTest extends TestCase
{
    private function current(): ?array
    {
        return $this->getJson('/api/contest/current')->assertStatus(200)->json();
    }

    /**
     * O contest de TREINO (#43) nunca e "o evento".
     *
     * Sem o scope competition(), o primeiro is_active da tabela podia ser o
     * de treino, e o relogio da prova mostraria o relogio do treino. E o
     * mesmo defeito que o #225 acabou de consertar nos atalhos legados.
     */
    public function test_the_practice_contest_is_never_the_event(): void
    {
        Contest::factory()->running(30)->create(['is_practice' => true, 'name' => 'Treino Livre']);
        Contest::factory()->running(30)->create(['is_practice' => false, 'name' => 'Maratona']);

        $this->assertSame('Maratona', $this->current()['name']);
    }

    /**
     * Acabar e congelar sao coisas diferentes desde o #189: a prova termina
     * e o placar continua congelado ate alguem revelar. O relogio precisa
     * dos dois para nao esconder o aviso cedo demais.
     */
    public function test_a_finished_contest_still_reports_its_freeze(): void
    {
        $contest = Contest::factory()->finished()->create();

        $body = $this->current();

        $this->assertTrue($body['is_ended']);
        $this->assertFalse($body['is_running']);
        $this->assertTrue($body['is_frozen'], 'acabar nao descongela');
        $this->assertFalse($body['is_unfrozen']);
    }

    /**
     * `is_frozen: false` sozinho nao distingue "nunca congelou" de "foi
     * revelado" -- e a cerimonia e exatamente a segunda.
     */
    public function test_revealing_is_distinguishable_from_never_having_frozen(): void
    {
        $contest = Contest::factory()->finished()->create();

        $semCongelamento = $this->current();
        $this->assertTrue($semCongelamento['is_frozen']);

        $contest->update(['unfrozen_at' => now()]);

        $revelado = $this->current();
        $this->assertFalse($revelado['is_frozen']);
        $this->assertTrue($revelado['is_unfrozen']);
        $this->assertNotNull($revelado['unfrozen_at']);

        $nuncaCongelou = Contest::factory()->running(10)->create();
        $contest->update(['is_active' => false]);

        $body = $this->current();
        $this->assertFalse($body['is_frozen']);
        $this->assertFalse($body['is_unfrozen'], 'nunca congelou nao e o mesmo que revelado');
    }

    public function test_the_finalized_state_is_visible(): void
    {
        $contest = Contest::factory()->finished()->create(['unfrozen_at' => now()]);

        $this->assertFalse($this->current()['is_finalized']);

        app(ContestFinalizer::class)->finalize($contest, null);

        $body = $this->current();
        $this->assertTrue($body['is_finalized']);
        $this->assertNotNull($body['finalized_at']);
    }

    /**
     * O fim vem do servidor porque o cliente nao tem como calcula-lo: desde
     * o #198 ele inclui os intervalos removidos, e desde o #225 pode ter
     * sido encurtado por um encerramento antecipado. start_time + duration
     * nao basta mais.
     */
    public function test_the_end_time_reflects_an_early_end(): void
    {
        $contest = Contest::factory()->running(100)->create();
        $antes = $this->current()['end_time'];

        app(ContestLifecycle::class)->endEarly($contest->fresh(), null);

        $depois = $this->current()['end_time'];

        $this->assertNotSame($antes, $depois, 'encerrar mais cedo tem que mover o fim');
        $this->assertFalse($this->current()['is_running']);
    }

    /**
     * E reflete os intervalos REMOVIDOS (#198).
     *
     * Este caso existe separado do anterior porque encerrar mais cedo muda a
     * propria `duration` -- entao `start_time + duration` da a mesma
     * resposta e o teste nao distinguia as duas formulas. Uma mutacao
     * mostrou isso. Com tempo removido, a conta ingenua erra por exatamente
     * o tanto que foi descontado, e a interface mostraria a prova acabando
     * cedo demais.
     */
    public function test_the_end_time_includes_removed_intervals(): void
    {
        $contest = Contest::factory()->running(100)->create();
        $ingenuo = $contest->start_time->copy()->addMinutes($contest->duration);

        ContestTimeAdjustment::create([
            'contest_id' => $contest->id,
            'site_id' => null,
            'starts_at' => $contest->start_time->copy()->addMinutes(20),
            'ends_at' => $contest->start_time->copy()->addMinutes(60),
            'reason' => 'queda de energia',
        ]);
        app(ContestClock::class)->forget();

        $informado = Carbon::parse($this->current()['end_time']);

        $this->assertSame(
            40 * 60,
            (int) $ingenuo->diffInSeconds($informado),
            'o fim tem que andar os quarenta minutos removidos'
        );
    }

    /**
     * O relogio do servidor, para a interface medir a propria defasagem em
     * vez de confiar no relogio da maquina de quem olha.
     */
    public function test_the_server_clock_travels_with_the_state(): void
    {
        Contest::factory()->running(10)->create();

        $this->assertNotNull($this->current()['server_time']);
    }

    /**
     * Sem competicao ativa a resposta e um OBJETO VAZIO, e nao `null`.
     *
     * A rota escreve `response()->json(null)`, e o Laravel serializa isso
     * como `{}`. A diferenca importa para quem consome: `{}` e TRUTHY, entao
     * um `if (data)` renderiza um relogio para um contest que nao existe. O
     * componente atual escapa porque testa `data?.start_time`, e nao a
     * existencia do objeto.
     *
     * Fixado como esta, e nao "consertado" para `null`: o consumidor de hoje
     * ja lida certo com o formato atual, e trocar a forma do fio por
     * elegancia quebraria quem o le. O que vale e a armadilha estar escrita,
     * para quem for reescrever o relogio no #223 nao supor que a verdade
     * seja a outra.
     */
    public function test_with_no_active_competition_the_body_is_an_empty_object(): void
    {
        Contest::factory()->running(10)->create(['is_active' => false]);

        $content = trim($this->getJson('/api/contest/current')->assertStatus(200)->getContent());

        $this->assertSame('{}', $content);
        $this->assertNotSame('null', $content, 'se isto mudar, o aviso acima precisa mudar junto');
    }
}
