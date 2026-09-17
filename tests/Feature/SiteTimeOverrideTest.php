<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Site;
use App\Services\ContestClock;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #276 -- `sites.duration` e `sites.freeze_time` passam a ser honradas.
 *
 * As colunas existiam desde a migracao inicial, com comentario dizendo
 * "Site-specific duration override", e `Site::getEffectiveDuration()` /
 * `getEffectiveFreezeTime()` sempre implementaram o fallback corretamente.
 * Os unicos chamadores no repositorio inteiro eram dois testes de unidade, e
 * nenhum caminho GRAVAVA as colunas -- nem UI, nem API, nem importador.
 * Morto nas duas pontas.
 *
 * Teste verde contra mecanismo desligado e o modo de falha catalogado deste
 * repositorio: `SiteModelTest` provava que o metodo calculava o override
 * certo, e o metodo nao era chamado por ninguem. Quem lesse a suite
 * concluiria que funcionava; quem configurasse uma sede descobriria na prova
 * que nao.
 *
 * Atende RF-F04-001 (configurar) e RF-F04-002 (fallback) do SRS.
 *
 * Os numeros sao escolhidos para que confundir sede com contest nao possa
 * coincidir com o certo: prova de 300, sede de 240, congelamento de 60 no
 * contest e 30 na sede.
 */
class SiteTimeOverrideTest extends TestCase
{
    use RefreshDatabase;

    private Contest $contest;

    private Site $curta;

    private Site $padrao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create([
            'start_time' => now()->subMinutes(200),
            'duration' => 300,
            'freeze_time' => 60,
            'is_active' => true,
            'is_public' => true,
        ]);

        // Duracao propria de 240 e congelamento proprio de 30: congela aos
        // 210 minutos, enquanto o contest congelaria aos 240.
        $this->curta = Site::factory()->create([
            'contest_id' => $this->contest->id,
            'name' => 'Sede Curta',
            'duration' => 240,
            'freeze_time' => 30,
        ]);

        $this->padrao = Site::factory()->create([
            'contest_id' => $this->contest->id,
            'name' => 'Sede Padrao',
            'duration' => null,
            'freeze_time' => null,
        ]);
    }

    private function clock(): ContestClock
    {
        return app(ContestClock::class);
    }

    private function fimEsperado(int $minutos): string
    {
        return $this->contest->start_time->copy()->addMinutes($minutos)->toDateTimeString();
    }

    // ---------------------------------------------------------------
    // RF-F04-001 -- a duracao da sede vale.
    // ---------------------------------------------------------------

    public function test_a_site_with_its_own_duration_ends_at_its_own_time(): void
    {
        $this->assertSame(
            $this->fimEsperado(240),
            $this->clock()->endTimeFor($this->contest, $this->curta->id)->toDateTimeString(),
            'a sede com duracao propria terminou no horario do contest'
        );
    }

    /**
     * RF-F04-002 -- e a sede sem override segue o contest. Este e o controle
     * positivo da duracao: sem ele, tudo acima passaria igual se
     * `endTimeFor()` passasse a devolver sempre 240.
     */
    public function test_a_site_without_an_override_follows_the_contest(): void
    {
        $this->assertSame(
            $this->fimEsperado(300),
            $this->clock()->endTimeFor($this->contest, $this->padrao->id)->toDateTimeString(),
            'a sede sem override deixou de seguir o contest'
        );
    }

    /**
     * O portao de submissao e o que importa de verdade: `isRunningFor()` sai
     * de `endTimeFor()`, e os dois caminhos de envio decidem por ele.
     *
     * Aos 250 minutos, a sede curta (240) acabou e a padrao (300) nao.
     */
    public function test_the_submission_gate_closes_for_the_short_site_first(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(250)]);
        $this->contest->refresh();

        $this->assertFalse(
            $this->clock()->isRunningFor($this->contest, $this->curta->id),
            'a sede curta continuou aceitando envio depois do fim dela'
        );

        $this->assertTrue(
            $this->clock()->isRunningFor($this->contest, $this->padrao->id),
            'a sede padrao parou de aceitar envio antes do fim do contest'
        );
    }

    // ---------------------------------------------------------------
    // O congelamento por sede.
    // ---------------------------------------------------------------

    /**
     * Aos 220 minutos: a sede curta ja congelou (240 - 30 = 210) e a padrao
     * ainda nao (300 - 60 = 240).
     */
    public function test_the_freeze_window_opens_per_site(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(220)]);
        $this->contest->refresh();

        $this->assertTrue(
            $this->clock()->isFrozenFor($this->contest, $this->curta->id),
            'a sede com congelamento proprio nao congelou na janela dela'
        );

        $this->assertFalse(
            $this->clock()->isFrozenFor($this->contest, $this->padrao->id),
            'a sede sem override congelou fora da janela do contest'
        );
    }

    /**
     * Antes de qualquer janela abrir, ninguem esta congelado -- senao os dois
     * testes acima passariam por congelar sempre.
     */
    public function test_nobody_is_frozen_before_the_first_window(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(100)]);
        $this->contest->refresh();

        $this->assertFalse($this->clock()->isFrozenFor($this->contest, $this->curta->id));
        $this->assertFalse($this->clock()->isFrozenFor($this->contest, $this->padrao->id));
        $this->assertFalse($this->clock()->isFrozenForAnyone($this->contest));
    }

    /**
     * A pergunta conservadora, que e a que `Contest::isFrozen()` passou a
     * fazer: assim que a PRIMEIRA sede entra na janela dela, revelar o quadro
     * inteiro entregaria o que aquela sede esconde.
     */
    public function test_the_conservative_question_is_true_as_soon_as_one_site_freezes(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(220)]);
        $this->contest->refresh();

        $this->assertTrue($this->clock()->isFrozenForAnyone($this->contest));
        $this->assertTrue($this->contest->isFrozen(), 'Contest::isFrozen() deixou de ser conservador');
    }

    /**
     * Zero na sede continua querendo dizer "sem congelamento", como o #189
     * exige -- agora por sede. E a outra sede congela igual.
     */
    public function test_zero_on_a_site_means_no_freeze_for_that_site_only(): void
    {
        $this->curta->update(['freeze_time' => 0]);
        $this->contest->update(['start_time' => now()->subMinutes(260)]);
        $this->contest->refresh();

        $this->assertFalse(
            $this->clock()->isFrozenFor($this->contest, $this->curta->id),
            'zero na sede deixou de querer dizer sem congelamento'
        );

        $this->assertTrue(
            $this->clock()->isFrozenFor($this->contest, $this->padrao->id),
            'a outra sede deixou de congelar'
        );
    }

    /**
     * O caso oposto, que o override torna possivel: prova sem congelamento
     * nenhum, sede que define trinta minutos.
     */
    public function test_a_site_can_freeze_in_a_contest_that_does_not(): void
    {
        $this->contest->update(['freeze_time' => 0, 'start_time' => now()->subMinutes(220)]);
        $this->contest->refresh();

        $this->assertTrue(
            $this->clock()->isFrozenFor($this->contest, $this->curta->id),
            'a sede com congelamento proprio nao congelou numa prova sem congelamento'
        );

        $this->assertFalse(
            $this->clock()->isFrozenFor($this->contest, $this->padrao->id),
            'a sede sem override congelou numa prova sem congelamento'
        );
    }

    /**
     * Revelar encerra tudo, em toda sede. `unfrozen_at` continua sendo a
     * unica coisa que termina um congelamento (#189).
     */
    public function test_releasing_the_standings_unfreezes_every_site(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(260), 'unfrozen_at' => now()]);
        $this->contest->refresh();

        $this->assertFalse($this->clock()->isFrozenFor($this->contest, $this->curta->id));
        $this->assertFalse($this->clock()->isFrozenFor($this->contest, $this->padrao->id));
        $this->assertFalse($this->contest->isFrozen());
    }

    /**
     * Uma prova sem sede nenhuma cai no calculo do proprio contest -- caso do
     * Treino Livre e de qualquer prova antes de a primeira sede existir.
     */
    public function test_a_contest_with_no_sites_falls_back_to_its_own_window(): void
    {
        $sozinho = Contest::factory()->create([
            'start_time' => now()->subMinutes(260),
            'duration' => 300,
            'freeze_time' => 60,
            'is_active' => true,
        ]);

        $this->assertTrue($sozinho->isFrozen(), 'a prova sem sede deixou de congelar');
        $this->assertSame(
            $sozinho->start_time->copy()->addMinutes(300)->toDateTimeString(),
            app(ContestClock::class)->endTimeFor($sozinho, null)->toDateTimeString()
        );
    }

    /**
     * Sede de OUTRA prova cai no contest, e nao na duracao dela. Sem isto,
     * um `site_id` errado na sessao mudaria silenciosamente o relogio.
     */
    public function test_a_site_from_another_contest_falls_back_to_this_contest(): void
    {
        $outra = Site::factory()->create(['duration' => 60, 'freeze_time' => 5]);

        $this->assertSame(
            $this->fimEsperado(300),
            $this->clock()->endTimeFor($this->contest, $outra->id)->toDateTimeString(),
            'a sede de outra prova mexeu no relogio desta'
        );
    }

    // ---------------------------------------------------------------
    // O placar por espectador -- o risco que a decisao aceitou.
    // ---------------------------------------------------------------

    /**
     * Aos 220 minutos, a equipe da sede curta ve congelado e a da sede padrao
     * nao. E esse o comportamento escolhido.
     */
    public function test_the_scoreboard_answers_per_viewer_site(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(220)]);
        $this->contest->refresh();

        $daCurta = $this->createTestUser([
            'email' => 'curta@example.com',
            'contest_id' => $this->contest->id,
            'site_id' => $this->curta->id,
        ]);

        $daPadrao = $this->createTestUser([
            'email' => 'padrao@example.com',
            'contest_id' => $this->contest->id,
            'site_id' => $this->padrao->id,
        ]);

        // A tela web, que e o que a equipe olha.
        $this->actingAs($daCurta)->get('/scoreboard')
            ->assertSuccessful()
            ->assertViewHas('frozen', true);

        $this->actingAs($daPadrao)->get('/scoreboard')
            ->assertSuccessful()
            ->assertViewHas('frozen', false);

        // E a API, pela mesma regra -- as duas nao podem discordar.
        Sanctum::actingAs($daCurta);
        $this->getJson("/api/contests/{$this->contest->id}/scoreboard")
            ->assertSuccessful()
            ->assertJsonPath('contest.is_frozen', true);

        Sanctum::actingAs($daPadrao);
        $this->getJson("/api/contests/{$this->contest->id}/scoreboard")
            ->assertSuccessful()
            ->assertJsonPath('contest.is_frozen', false);
    }

    /**
     * Espectador sem sede cai no conservador: congelado enquanto qualquer
     * sede ainda esconder. Nao ha como saber de qual sede viria a
     * classificacao que ele veria.
     */
    public function test_a_viewer_with_no_site_gets_the_conservative_answer(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(220)]);
        $this->contest->refresh();

        $semSede = $this->createTestUser([
            'email' => 'semsede@example.com',
            'contest_id' => $this->contest->id,
            'site_id' => null,
            'user_type' => User::TYPE_TEAM,
        ]);

        $this->actingAs($semSede)->get('/scoreboard')
            ->assertSuccessful()
            ->assertViewHas('frozen', true);
    }

    /**
     * E a organizacao continua isenta, por sede ou sem: a regra do #211 nao
     * muda -- "quem e da organizacao" nao pode existir em duas versoes.
     */
    public function test_staff_is_still_exempt_whatever_their_site(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(220)]);
        $this->contest->refresh();

        $juiz = $this->createTestUser([
            'email' => 'juiz@example.com',
            'user_type' => User::TYPE_JUDGE,
            'contest_id' => $this->contest->id,
            'site_id' => $this->curta->id,
        ]);

        $this->actingAs($juiz)->get('/scoreboard')
            ->assertSuccessful()
            ->assertViewHas('frozen', false);
    }

    // ---------------------------------------------------------------
    // RF-F04-001, a outra metade: dá para configurar.
    // ---------------------------------------------------------------

    public function test_an_admin_can_save_a_site_override(): void
    {
        $admin = $this->createTestUser([
            'email' => 'admin@example.com',
            'user_type' => User::TYPE_ADMIN,
        ]);

        $this->actingAs($admin)->put(route('backend.sites.update', $this->padrao), [
            'name' => $this->padrao->name,
            'score_visibility' => 'all',
            'max_judge_wait_time' => 900,
            'is_active' => 1,
            'duration' => 180,
            'freeze_time' => 20,
        ])->assertRedirect();

        $this->padrao->refresh();

        $this->assertSame(180, (int) $this->padrao->duration, 'a duracao propria nao foi gravada');
        $this->assertSame(20, (int) $this->padrao->freeze_time, 'o congelamento proprio nao foi gravado');
    }

    /**
     * Campo em branco quer dizer "segue o contest", e nao zero.
     *
     * Um `(int)` cru na string vazia daria ZERO, e zero em `duration` e uma
     * prova de duracao nula -- a sede nao aceitaria envio nenhum.
     */
    public function test_an_empty_field_clears_the_override_instead_of_zeroing_it(): void
    {
        $admin = $this->createTestUser([
            'email' => 'admin2@example.com',
            'user_type' => User::TYPE_ADMIN,
        ]);

        $this->actingAs($admin)->put(route('backend.sites.update', $this->curta), [
            'name' => $this->curta->name,
            'score_visibility' => 'all',
            'max_judge_wait_time' => 900,
            'is_active' => 1,
            'duration' => '',
            'freeze_time' => '',
        ])->assertRedirect();

        $this->curta->refresh();

        $this->assertNull($this->curta->duration, 'o campo vazio virou zero em vez de nulo');
        $this->assertNull($this->curta->freeze_time, 'o campo vazio virou zero em vez de nulo');

        $this->assertSame(
            $this->fimEsperado(300),
            $this->clock()->endTimeFor($this->contest->fresh(), $this->curta->id)->toDateTimeString(),
            'a sede limpa deixou de seguir o contest'
        );
    }

    /**
     * Zero em `freeze_time` e escolha legitima ("sem congelamento nesta
     * sede") e tem de sobreviver a gravacao -- diferente do branco.
     */
    public function test_an_explicit_zero_freeze_is_stored_as_zero(): void
    {
        $admin = $this->createTestUser([
            'email' => 'admin3@example.com',
            'user_type' => User::TYPE_ADMIN,
        ]);

        $this->actingAs($admin)->put(route('backend.sites.update', $this->curta), [
            'name' => $this->curta->name,
            'score_visibility' => 'all',
            'max_judge_wait_time' => 900,
            'is_active' => 1,
            'duration' => 240,
            'freeze_time' => 0,
        ])->assertRedirect();

        $this->curta->refresh();

        $this->assertSame(0, (int) $this->curta->freeze_time);
        $this->assertNotNull($this->curta->freeze_time, 'o zero explicito virou nulo');
    }
}
