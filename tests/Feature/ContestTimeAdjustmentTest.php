<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\ContestTimeAdjustment;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Score;
use App\Models\Site;
use App\Services\ContestClock;
use App\Services\ContestScoreRecomputer;
use Helium\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #198 -- um pedaco de tempo que a prova nao conta.
 *
 * Cai a energia numa sede as 14h e volta as 14h40. As equipes daquela sede
 * perderam quarenta minutos e as outras nao. Ate aqui nao havia o que fazer:
 * o relogio era start_time + duration, e as opcoes eram deixar a sede perder
 * o tempo ou mexer no start_time de todo mundo -- que estraga os
 * contest_time ja gravados.
 *
 * Nao e hipotese: o Manual do Diretor de Sede da Maratona tem protocolo
 * escrito para queda de energia. Hoje a sede faz a conta no papel e a
 * classificacao nao bate com o placar.
 */
class ContestTimeAdjustmentTest extends TestCase
{
    private Contest $contest;

    private Site $afetada;

    private Site $intacta;

    private Problem $problem;

    private Answer $yes;

    private Language $language;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Comecou ha 200 minutos, dura 300.
        $this->contest = Contest::factory()->running(200)->create();
        $this->afetada = Site::factory()->create(['contest_id' => $this->contest->id, 'name' => 'Sede Afetada']);
        $this->intacta = Site::factory()->create(['contest_id' => $this->contest->id, 'name' => 'Sede Intacta']);
        $this->problem = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'A']);
        $this->yes = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'YES', 'is_accepted' => true]);
        Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'NO', 'is_accepted' => false]);
        $this->language = Language::factory()->create(['contest_id' => $this->contest->id]);
        $this->admin = $this->createTestUser(['user_type' => 'admin', 'contest_id' => $this->contest->id]);
    }

    private function team(Site $site, string $name): User
    {
        return $this->createTestUser(['fullname' => $name, 'contest_id' => $this->contest->id, 'site_id' => $site->id]);
    }

    private function solveAt(User $team, int $minute): Run
    {
        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $team->site_id,
            'user_id' => $team->user_id,
            'problem_id' => $this->problem->id,
            'language_id' => $this->language->id,
            'status' => 'judged',
            'answer_id' => $this->yes->id,
            'contest_time' => $minute * 60,
            'judged_time' => $minute * 60,
        ]);

        Score::updateScore($run);

        return $run;
    }

    /** Remove o intervalo [$fromMinute, $toMinute) da prova, para $site (ou global). */
    private function remove(int $fromMinute, int $toMinute, ?Site $site = null): ContestTimeAdjustment
    {
        return ContestTimeAdjustment::create([
            'contest_id' => $this->contest->id,
            'site_id' => $site?->id,
            'starts_at' => $this->contest->start_time->copy()->addMinutes($fromMinute),
            'ends_at' => $this->contest->start_time->copy()->addMinutes($toMinute),
            'reason' => 'queda de energia',
            'created_by' => $this->admin->user_id,
        ]);
    }

    private function clock(): ContestClock
    {
        $clock = app(ContestClock::class);
        $clock->forget();

        return $clock;
    }

    private function solvedTimeOf(User $team): int
    {
        return (int) Score::where('user_id', $team->user_id)->where('problem_id', $this->problem->id)->value('solved_time');
    }

    // -- o relogio ----------------------------------------------------------

    public function test_time_before_the_removed_interval_is_untouched(): void
    {
        $this->remove(120, 160, $this->afetada);

        $this->assertSame(60 * 60, $this->clock()->adjusted($this->contest, $this->afetada->id, 60 * 60));
    }

    public function test_time_after_the_removed_interval_loses_exactly_the_interval(): void
    {
        $this->remove(120, 160, $this->afetada);

        // Aos 180 minutos crus, quarenta deles nao contam.
        $this->assertSame(140 * 60, $this->clock()->adjusted($this->contest, $this->afetada->id, 180 * 60));
    }

    /**
     * Um envio DENTRO do intervalo removido colapsa para o comeco dele. Sem
     * isso ele receberia o desconto inteiro e apareceria ANTES da queda ter
     * comecado.
     */
    public function test_a_submission_inside_the_removed_interval_collapses_to_its_start(): void
    {
        $this->remove(120, 160, $this->afetada);

        $this->assertSame(120 * 60, $this->clock()->adjusted($this->contest, $this->afetada->id, 140 * 60));
    }

    public function test_another_site_is_not_affected_by_a_site_scoped_removal(): void
    {
        $this->remove(120, 160, $this->afetada);

        $this->assertSame(180 * 60, $this->clock()->adjusted($this->contest, $this->intacta->id, 180 * 60));
    }

    public function test_a_global_removal_reaches_every_site(): void
    {
        $this->remove(120, 160);

        $this->assertSame(140 * 60, $this->clock()->adjusted($this->contest, $this->afetada->id, 180 * 60));
        $this->assertSame(140 * 60, $this->clock()->adjusted($this->contest, $this->intacta->id, 180 * 60));
    }

    // -- a exigencia de ordem -----------------------------------------------

    /**
     * "If submission S_i arrived before submission S_j during a removed
     *  interval, S_i must still be considered by the CCS to have arrived
     *  strictly before S_j."
     *
     * Os dois colapsam para o mesmo instante ajustado -- entao a ORDEM tem
     * que vir do tempo cru, que e de onde ela vem.
     */
    public function test_two_submissions_inside_the_removed_interval_keep_their_order(): void
    {
        $time = $this->team($this->afetada, 'Equipe');
        $errada = Answer::where('contest_id', $this->contest->id)->where('short_name', 'NO')->first();

        $primeiro = Run::factory()->create([
            'contest_id' => $this->contest->id, 'site_id' => $this->afetada->id,
            'user_id' => $time->user_id, 'problem_id' => $this->problem->id,
            'status' => 'judged', 'answer_id' => $errada->id, 'contest_time' => 130 * 60,
        ]);
        $segundo = $this->solveAt($time, 150);

        $this->remove(120, 160, $this->afetada);
        app(ContestScoreRecomputer::class)->recompute($this->contest);

        // Os dois valem 120 minutos ajustados, e ainda assim o errado conta
        // como tentativa anterior a aceita -- que e o que a exigencia diz.
        $this->assertLessThan($segundo->id, $primeiro->id);
        $this->assertSame(2, (int) Score::where('user_id', $time->user_id)->value('attempts'));
        $this->assertSame(120, $this->solvedTimeOf($time));
    }

    // -- o placar -----------------------------------------------------------

    public function test_removing_an_interval_gives_the_affected_site_its_time_back_on_the_scoreboard(): void
    {
        $afetada = $this->team($this->afetada, 'Afetada');
        $intacta = $this->team($this->intacta, 'Intacta');
        $this->solveAt($afetada, 180);
        $this->solveAt($intacta, 180);

        $this->assertSame(180, $this->solvedTimeOf($afetada));

        $this->remove(120, 160, $this->afetada);
        app(ContestScoreRecomputer::class)->recompute($this->contest);

        $this->assertSame(140, $this->solvedTimeOf($afetada), 'a sede afetada deveria ter os 40 minutos de volta');
        $this->assertSame(180, $this->solvedTimeOf($intacta), 'a sede intacta nao deveria mudar');
    }

    /**
     * "The removal of a time interval must be reversible."
     *
     * Desfazer nao precisa lembrar de nada: nenhum contest_time foi
     * reescrito, e o placar e derivado e nao acumulado.
     */
    public function test_reverting_the_removal_puts_the_scoreboard_back(): void
    {
        $time = $this->team($this->afetada, 'Afetada');
        $this->solveAt($time, 180);

        $ajuste = $this->remove(120, 160, $this->afetada);
        app(ContestScoreRecomputer::class)->recompute($this->contest);
        $this->assertSame(140, $this->solvedTimeOf($time));

        $ajuste->delete();
        app(ContestScoreRecomputer::class)->recompute($this->contest);

        $this->assertSame(180, $this->solvedTimeOf($time), 'desfazer deveria devolver o placar ao que era');
    }

    // -- o fim da prova -----------------------------------------------------

    /**
     * O ponto inteiro: uma sede que perdeu quarenta minutos submete quarenta
     * minutos depois de as outras terem acabado. Sem isso, o intervalo
     * removido corrigiria o placar e deixaria a equipe sem poder usar o
     * tempo que lhe foi devolvido.
     */
    public function test_the_affected_site_can_still_submit_after_the_others_are_done(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(310)]);
        $this->contest->refresh();
        $this->remove(120, 160, $this->afetada);

        $clock = $this->clock();

        $this->assertFalse($clock->isRunningFor($this->contest, $this->intacta->id), 'a sede intacta ja acabou');
        $this->assertTrue($clock->isRunningFor($this->contest, $this->afetada->id), 'a sede afetada ainda tem tempo');
    }

    public function test_the_submission_endpoint_honours_the_site_extension(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(310)]);
        $this->contest->refresh();
        $this->remove(120, 160, $this->afetada);

        $afetada = $this->team($this->afetada, 'Afetada');
        $intacta = $this->team($this->intacta, 'Intacta');

        Queue::fake();

        Sanctum::actingAs($intacta);
        $this->postJson('/api/runs', [
            'contest_id' => $this->contest->id,
            'problem_id' => $this->problem->id,
            'language_id' => $this->language->id,
            'source_file' => UploadedFile::fake()->create('a.c', 1, 'text/plain'),
        ])->assertStatus(422)->assertJsonPath('error', 'Contest is not running');

        // forgetGuards(): o Laravel resolve a aplicacao uma vez por metodo de
        // teste, entao o guard do Sanctum guarda o usuario ja resolvido entre
        // requisicoes -- sem isto a segunda chamada continuaria sendo a
        // primeira equipe.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($afetada);
        $this->postJson('/api/runs', [
            'contest_id' => $this->contest->id,
            'problem_id' => $this->problem->id,
            'language_id' => $this->language->id,
            'source_file' => UploadedFile::fake()->create('a.c', 1, 'text/plain'),
        ])->assertStatus(201);
    }

    /**
     * Um intervalo removido da prova INTEIRA empurra o fim de todo mundo.
     */
    public function test_a_global_removal_moves_the_contest_end(): void
    {
        $antes = $this->contest->end_time->copy();

        $this->remove(120, 160);
        app(ContestClock::class)->forget();

        $this->assertSame(40 * 60, (int) $antes->diffInSeconds($this->contest->fresh()->end_time));
    }

    // -- a porta ------------------------------------------------------------

    public function test_the_api_records_and_reverts_an_interval(): void
    {
        $time = $this->team($this->afetada, 'Afetada');
        $this->solveAt($time, 180);

        Sanctum::actingAs($this->admin);
        $id = $this->postJson("/api/contests/{$this->contest->id}/time-adjustments", [
            'site_id' => $this->afetada->id,
            'starts_at' => $this->contest->start_time->copy()->addMinutes(120)->toISOString(),
            'ends_at' => $this->contest->start_time->copy()->addMinutes(160)->toISOString(),
            'reason' => 'queda de energia',
        ])->assertStatus(201)->json('data.id');

        $this->assertSame(140, $this->solvedTimeOf($time), 'criar o intervalo ja deveria ter recomposto o placar');

        $this->deleteJson("/api/contests/{$this->contest->id}/time-adjustments/{$id}")->assertStatus(200);

        $this->assertSame(180, $this->solvedTimeOf($time));
    }

    /**
     * Desfeitos continuam listados: "reversivel" nao combina com sumir do
     * registro, e alguem vai perguntar depois por que aquela sede teve
     * quarenta minutos a mais.
     */
    public function test_a_reverted_interval_stays_in_the_record(): void
    {
        $ajuste = $this->remove(120, 160, $this->afetada);
        $ajuste->delete();

        Sanctum::actingAs($this->admin);
        $linhas = $this->getJson("/api/contests/{$this->contest->id}/time-adjustments")->assertStatus(200)->json('data');

        $this->assertCount(1, $linhas);
        $this->assertTrue($linhas[0]['reverted']);
        $this->assertSame('queda de energia', $linhas[0]['reason']);
    }

    public function test_the_reason_is_required(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/contests/{$this->contest->id}/time-adjustments", [
            'starts_at' => $this->contest->start_time->copy()->addMinutes(120)->toISOString(),
            'ends_at' => $this->contest->start_time->copy()->addMinutes(160)->toISOString(),
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_an_interval_that_ends_before_it_starts_is_refused(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/contests/{$this->contest->id}/time-adjustments", [
            'starts_at' => $this->contest->start_time->copy()->addMinutes(160)->toISOString(),
            'ends_at' => $this->contest->start_time->copy()->addMinutes(120)->toISOString(),
            'reason' => 'invertido',
        ])->assertStatus(422)->assertJsonValidationErrors('ends_at');
    }

    public function test_a_team_cannot_remove_time_from_the_contest(): void
    {
        Sanctum::actingAs($this->team($this->afetada, 'Equipe'));

        $this->postJson("/api/contests/{$this->contest->id}/time-adjustments", [
            'starts_at' => $this->contest->start_time->copy()->addMinutes(120)->toISOString(),
            'ends_at' => $this->contest->start_time->copy()->addMinutes(160)->toISOString(),
            'reason' => 'quero mais tempo',
        ])->assertStatus(403);
    }
}
