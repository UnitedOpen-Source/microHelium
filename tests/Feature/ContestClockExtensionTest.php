<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\ContestTimeAdjustment;
use App\Models\Site;
use App\Services\ContestClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #287 -- a extensao entra uma vez, e nao duas.
 *
 * `endTimeFor()` partia de `$contest->end_time`, que ja tinha somado a
 * extensao GLOBAL, e somava por cima `extensionSeconds($contest, $siteId)` --
 * que inclui os globais de novo, porque um ajuste global vale para toda sede.
 *
 * Nao era erro de tela. `isRunningFor()` sai dali, e os dois caminhos de
 * envio decidem por ele: uma queda de energia nacional de 40 minutos dava 80
 * minutos extras de submissao a todas as sedes.
 *
 * Os numeros aqui sao escolhidos para que dobrar NAO possa coincidir com o
 * certo: 30 != 60, 20 != 40, e no teste combinado 50 != 80.
 */
class ContestClockExtensionTest extends TestCase
{
    use RefreshDatabase;

    private Contest $contest;

    private Site $alfa;

    private Site $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create([
            'start_time' => now()->subHours(2),
            'duration' => 300,
            'is_active' => true,
        ]);

        $this->alfa = Site::factory()->create(['contest_id' => $this->contest->id, 'name' => 'Alfa']);
        $this->beta = Site::factory()->create(['contest_id' => $this->contest->id, 'name' => 'Beta']);
    }

    private function ajuste(?int $siteId, int $minutos, int $comecaEm = 10): void
    {
        ContestTimeAdjustment::create([
            'contest_id' => $this->contest->id,
            'site_id' => $siteId,
            'starts_at' => $this->contest->start_time->copy()->addMinutes($comecaEm),
            'ends_at' => $this->contest->start_time->copy()->addMinutes($comecaEm + $minutos),
            'reason' => 'queda de energia',
            'created_by' => null,
        ]);
    }

    private function fimEm(?int $siteId): string
    {
        return app(ContestClock::class)->endTimeFor($this->contest, $siteId)->toDateTimeString();
    }

    private function esperado(int $minutosDeExtensao): string
    {
        return $this->contest->start_time->copy()
            ->addMinutes(300 + $minutosDeExtensao)
            ->toDateTimeString();
    }

    public function test_a_global_adjustment_extends_once_and_not_twice(): void
    {
        $this->ajuste(siteId: null, minutos: 30);

        $this->assertSame(
            $this->esperado(30),
            $this->fimEm($this->alfa->id),
            'o ajuste global entrou mais de uma vez no fim da sede'
        );
    }

    /**
     * Sem sede nenhuma envolvida, o defeito ja aparecia.
     */
    public function test_a_global_adjustment_extends_once_with_no_site_at_all(): void
    {
        $this->ajuste(siteId: null, minutos: 30);

        $this->assertSame($this->esperado(30), $this->fimEm(null));
    }

    /**
     * Controle positivo: sem ele, tudo acima passaria igual se
     * `endTimeFor()` simplesmente parasse de somar extensao nenhuma.
     */
    public function test_a_site_adjustment_still_extends_that_site(): void
    {
        $this->ajuste(siteId: $this->alfa->id, minutos: 20);

        $this->assertSame($this->esperado(20), $this->fimEm($this->alfa->id));
    }

    /**
     * O outro lado do mesmo controle: a extensao de uma sede nao vaza para a
     * vizinha.
     */
    public function test_a_site_adjustment_does_not_reach_the_other_site(): void
    {
        $this->ajuste(siteId: $this->alfa->id, minutos: 20);

        $this->assertSame($this->esperado(0), $this->fimEm($this->beta->id));
    }

    /**
     * Global e de sede juntos: 30 + 20 = 50, e nao 80 (que e o que o defeito
     * produzia) nem 30 (que e o que produziria esquecer o da sede).
     */
    public function test_a_global_and_a_site_adjustment_add_up_once_each(): void
    {
        $this->ajuste(siteId: null, minutos: 30, comecaEm: 10);
        $this->ajuste(siteId: $this->alfa->id, minutos: 20, comecaEm: 60);

        $this->assertSame($this->esperado(50), $this->fimEm($this->alfa->id), 'a soma nao bateu 30 + 20');
        $this->assertSame($this->esperado(30), $this->fimEm($this->beta->id), 'a sede sem ajuste proprio nao ficou so com o global');
    }

    /**
     * O portao de submissao e o que realmente importa: `isRunningFor()` sai
     * de `endTimeFor()`.
     *
     * A prova acabou ha 5 minutos em tempo cru. Com o ajuste global de 30, a
     * sede ainda tem 25 minutos. Com o defeito, teria 55 -- e o teste abaixo
     * so distingue os dois casos no instante em que a extensao CERTA ja
     * acabou e a dobrada ainda nao.
     */
    public function test_the_submission_gate_closes_at_the_single_extension(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(300 + 35)]);
        $this->contest->refresh();
        $this->ajuste(siteId: null, minutos: 30);

        // 335 minutos de parede, prova de 300 + 30 de extensao = 330.
        // Acabou ha 5 minutos. Dobrado seria 360, e ainda estaria aberta.
        $this->assertFalse(
            app(ContestClock::class)->isRunningFor($this->contest, $this->alfa->id),
            'o envio continuou aberto alem da extensao real'
        );
    }

    /**
     * E o controle do portao: cinco minutos ANTES, ele esta aberto.
     */
    public function test_the_submission_gate_is_open_inside_the_single_extension(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(300 + 25)]);
        $this->contest->refresh();
        $this->ajuste(siteId: null, minutos: 30);

        $this->assertTrue(
            app(ContestClock::class)->isRunningFor($this->contest, $this->alfa->id),
            'o envio fechou antes do fim da extensao'
        );
    }
}
