<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\ContestTimeAdjustment;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Score;
use App\Models\Site;
use App\Services\ContestClock;
use Helium\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Issue #198/#233 -- o fluxo de intervalos removidos na tela de operação.
 *
 * O backend existia desde o #198 e só era alcançável por chamada manual à
 * API. Esta é a tela que a organização usa quando cai a energia numa sede: o
 * Manual do Diretor de Sede da Maratona tem protocolo escrito para isso, e
 * sem a tela a sede fazia a conta no papel enquanto a classificação não
 * batia com o placar.
 */
class ContestTimeAdjustmentScreenTest extends TestCase
{
    private Contest $contest;

    private Site $afetada;

    private Site $intacta;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->running(200)->create();
        $this->afetada = Site::factory()->create(['contest_id' => $this->contest->id, 'name' => 'Sede Afetada']);
        $this->intacta = Site::factory()->create(['contest_id' => $this->contest->id, 'name' => 'Sede Intacta']);
        $this->admin = $this->createAdminUser();
    }

    private function registrar(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('backend.contest.operations', $this->contest))
            ->post(route('backend.contest.time-adjustments.store', $this->contest), array_merge([
                'site_id' => $this->afetada->id,
                'starts_at' => $this->contest->start_time->copy()->addMinutes(120)->format('Y-m-d\TH:i'),
                'ends_at' => $this->contest->start_time->copy()->addMinutes(160)->format('Y-m-d\TH:i'),
                'reason' => 'queda de energia na sede',
            ], $overrides));
    }

    // -- acesso -------------------------------------------------------------

    public function test_only_an_admin_reaches_the_flow(): void
    {
        $url = route('backend.contest.time-adjustments.store', $this->contest);

        $this->post($url)->assertRedirect('/login');
        $this->actingAs($this->createTestUser())->post($url)->assertForbidden();
    }

    /**
     * O contest de treino (#43) nunca é "o evento", e não tem cerimônia.
     */
    public function test_the_practice_contest_has_no_operations_flow(): void
    {
        $treino = Contest::factory()->running(10)->create(['is_practice' => true]);

        $this->actingAs($this->admin)
            ->post(route('backend.contest.time-adjustments.store', $treino))
            ->assertNotFound();
    }

    // -- registrar ----------------------------------------------------------

    public function test_registering_an_interval_recomputes_the_scoreboard(): void
    {
        $problem = Problem::factory()->create(['contest_id' => $this->contest->id]);
        $yes = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'YES', 'is_accepted' => true]);
        $team = $this->createTestUser(['contest_id' => $this->contest->id, 'site_id' => $this->afetada->id]);
        $run = Run::factory()->judged($yes)->at(180)->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->afetada->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
        ]);
        Score::updateScore($run->fresh());

        $this->assertSame(180, (int) Score::where('user_id', $team->user_id)->value('solved_time'));

        $this->registrar()->assertSessionHas('success');

        // Registrar já recompõe: deixar para depois faria a tela mostrar um
        // placar que ninguém mais acredita.
        $this->assertSame(140, (int) Score::where('user_id', $team->user_id)->value('solved_time'));
    }

    public function test_the_reason_is_required(): void
    {
        $this->registrar(['reason' => ''])->assertSessionHasErrors('reason');

        $this->assertSame(0, ContestTimeAdjustment::count());
    }

    public function test_an_interval_that_ends_before_it_starts_is_refused(): void
    {
        $this->registrar([
            'starts_at' => $this->contest->start_time->copy()->addMinutes(160)->format('Y-m-d\TH:i'),
            'ends_at' => $this->contest->start_time->copy()->addMinutes(120)->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('ends_at');
    }

    /**
     * Sede em branco é a competição inteira -- o `site_id` nulável é o que
     * faz as duas formas serem um mecanismo só.
     */
    public function test_a_blank_site_removes_the_interval_for_the_whole_contest(): void
    {
        $this->registrar(['site_id' => ''])->assertSessionHas('success');

        $this->assertNull(ContestTimeAdjustment::first()->site_id);
    }

    // -- desfazer -----------------------------------------------------------

    /**
     * "The removal of a time interval must be reversible." E desfeito
     * continua listado: "reversível" não combina com sumir do registro.
     */
    public function test_undoing_restores_the_scoreboard_and_keeps_the_record(): void
    {
        $problem = Problem::factory()->create(['contest_id' => $this->contest->id]);
        $yes = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'YES', 'is_accepted' => true]);
        $team = $this->createTestUser(['contest_id' => $this->contest->id, 'site_id' => $this->afetada->id]);
        $run = Run::factory()->judged($yes)->at(180)->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->afetada->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
        ]);
        Score::updateScore($run->fresh());

        $this->registrar();
        $adjustment = ContestTimeAdjustment::first();
        $this->assertSame(140, (int) Score::where('user_id', $team->user_id)->value('solved_time'));

        $this->actingAs($this->admin)
            ->from(route('backend.contest.operations', $this->contest))
            ->delete(route('backend.contest.time-adjustments.destroy', [$this->contest, $adjustment]))
            ->assertSessionHas('success');

        $this->assertSame(180, (int) Score::where('user_id', $team->user_id)->value('solved_time'));
        $this->assertSame(1, ContestTimeAdjustment::withTrashed()->count(), 'o registro tem que sobreviver');

        $this->actingAs($this->admin)
            ->get(route('backend.contest.operations', $this->contest))
            ->assertOk()
            ->assertSee('Desfeito')
            ->assertSee('queda de energia na sede');
    }

    public function test_an_interval_from_another_contest_is_not_reachable(): void
    {
        $outro = Contest::factory()->running(60)->create();
        $alheio = ContestTimeAdjustment::create([
            'contest_id' => $outro->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->subMinutes(30),
            'reason' => 'de outra prova',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('backend.contest.time-adjustments.destroy', [$this->contest, $alheio]))
            ->assertNotFound();

        $this->assertFalse($alheio->fresh()->trashed());
    }

    // -- a tela -------------------------------------------------------------

    public function test_the_screen_shows_the_effective_deadline_per_site(): void
    {
        $this->registrar();

        $esperado = app(ContestClock::class)->endTimeFor($this->contest->fresh(), $this->afetada->id);

        // Sem esta coluna a extensão fica invisível até a prova acabar torto.
        $this->actingAs($this->admin)
            ->get(route('backend.contest.operations', $this->contest))
            ->assertOk()
            ->assertSee('Prazo de envio por sede')
            ->assertSee($esperado->format('d/m/Y H:i'));
    }

    /**
     * A regressão que a issue pede: uma extensão de UMA sede não pode
     * liberar a revelação do placar para todo mundo.
     */
    public function test_a_local_extension_does_not_unlock_revealing_the_board(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(310)]);
        $this->registrar();

        // A sede afetada ainda tem tempo, então revelar continua recusado.
        $this->assertTrue(app(ContestClock::class)->isRunningFor($this->contest->fresh(), $this->afetada->id));

        $this->actingAs($this->admin)
            ->from(route('backend.contest.operations', $this->contest))
            ->post(route('backend.contest.unfreeze', $this->contest));

        $this->assertNull(
            $this->contest->fresh()->unfrozen_at,
            'revelar com uma sede ainda submetendo publicaria o placar antes da hora'
        );
    }
}
