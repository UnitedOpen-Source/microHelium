<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Leaderboard;
use App\Models\Problem;
use App\Models\Rejudging;
use App\Models\RejudgingRun;
use App\Models\Run;
use App\Models\Score;
use App\Models\Site;
use App\Services\RejudgingService;
use Helium\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #192 -- rejulgar em lote, com previa, aplicavel ou cancelavel como
 * conjunto.
 *
 * O cenario nao e rejulgar um envio: e descobrir no meio da prova que o caso
 * de teste 7 do problema C esta errado e precisar que TODOS os envios do C
 * voltem para a fila.
 *
 * O julgamento de sombra nao roda aqui -- exige sandbox. O que estes testes
 * exercitam e a maquina de estados em volta dele: quem entra no conjunto,
 * o que a previa promete, o que aplicar grava, e o que cancelar NAO grava.
 * O resultado do julgamento e injetado direto nas linhas de membro, que e
 * exatamente o que RejudgeMemberJob escreveria.
 */
class RejudgingTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    private Problem $problemA;

    private Problem $problemB;

    private Answer $yes;

    private Answer $no;

    private Answer $tle;

    private User $team;

    private User $admin;

    private RejudgingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->contest = Contest::factory()->running(60)->create();
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problemA = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'A']);
        $this->problemB = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'B']);
        $this->yes = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'YES', 'is_accepted' => true]);
        $this->no = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'NO', 'is_accepted' => false]);
        $this->tle = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'TLE', 'is_accepted' => false]);

        $this->team = $this->createTestUser(['fullname' => 'Equipe', 'contest_id' => $this->contest->id, 'site_id' => $this->site->id]);
        $this->admin = $this->createTestUser(['user_type' => 'admin', 'contest_id' => $this->contest->id]);

        $this->service = app(RejudgingService::class);
    }

    /**
     * NAO se chama run(): PHPUnit\Framework\TestCase::run() e final, e um
     * metodo de mesmo nome e erro fatal na carga da classe -- a suite nem
     * comeca. Ja aconteceu neste repositorio antes.
     */
    private function makeRun(Problem $problem, Answer $answer, array $attributes = []): Run
    {
        $run = Run::factory()->create(array_merge([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $this->team->user_id,
            'problem_id' => $problem->id,
            'status' => 'judged',
            'answer_id' => $answer->id,
            'contest_time' => 600,
            'judged_time' => 600,
        ], $attributes));

        Score::updateScore($run);

        return $run;
    }

    private function set(array $filters = [], bool $includeAccepted = false, string $reason = 'caso de teste 7 estava errado'): Rejudging
    {
        return $this->service->create($this->contest, $filters, $reason, $includeAccepted, $this->admin);
    }

    /** O que RejudgeMemberJob escreveria quando o julgamento de sombra roda. */
    private function shadowVerdict(Rejudging $rejudging, Run $run, ?Answer $answer, ?string $error = null): void
    {
        RejudgingRun::where('rejudging_id', $rejudging->id)->where('run_id', $run->id)->first()->update([
            'new_answer_id' => $answer?->id,
            'new_verdict' => $answer?->short_name,
            'judged_at' => now(),
            'error' => $error,
        ]);

        $this->service->refreshReadiness($rejudging->fresh());
    }

    // -- selecao ------------------------------------------------------------

    public function test_a_whole_problem_can_be_selected_at_once(): void
    {
        $this->makeRun($this->problemA, $this->no);
        $this->makeRun($this->problemA, $this->no);
        $this->makeRun($this->problemB, $this->no);

        $rejudging = $this->set(['problem_id' => $this->problemA->id]);

        $this->assertSame(2, $rejudging->members()->count());
    }

    /**
     * Tirar um AC de uma equipe no meio da prova e a coisa mais cara que um
     * rejulgamento faz, e quase nunca e o que se queria quando se pediu
     * "rejulgue o problema C".
     */
    public function test_accepted_runs_are_excluded_unless_asked_for(): void
    {
        $this->makeRun($this->problemA, $this->yes);
        $this->makeRun($this->problemA, $this->no);

        $this->assertSame(1, $this->set(['problem_id' => $this->problemA->id])->members()->count());
        $this->assertSame(2, $this->set(['problem_id' => $this->problemA->id], includeAccepted: true)->members()->count());
    }

    public function test_the_filters_the_standard_names_all_work(): void
    {
        $outraLinguagem = Language::factory()->create(['contest_id' => $this->contest->id]);
        $outraSede = Site::factory()->create(['contest_id' => $this->contest->id]);
        $outraEquipe = $this->createTestUser(['contest_id' => $this->contest->id, 'site_id' => $this->site->id]);

        $alvo = $this->makeRun($this->problemA, $this->no, ['contest_time' => 600]);
        $this->makeRun($this->problemA, $this->no, ['language_id' => $outraLinguagem->id]);
        $this->makeRun($this->problemA, $this->no, ['site_id' => $outraSede->id]);
        $this->makeRun($this->problemA, $this->no, ['user_id' => $outraEquipe->user_id]);
        $this->makeRun($this->problemA, $this->tle);
        $this->makeRun($this->problemA, $this->no, ['contest_time' => 9000]);

        $this->assertSame(1, $this->set(['run_ids' => [$alvo->id]])->members()->count());
        $this->assertSame(1, $this->set(['language_id' => $outraLinguagem->id])->members()->count());
        $this->assertSame(1, $this->set(['site_id' => $outraSede->id])->members()->count());
        $this->assertSame(1, $this->set(['user_id' => $outraEquipe->user_id])->members()->count());
        $this->assertSame(1, $this->set(['answer_id' => $this->tle->id])->members()->count());
        $this->assertSame(1, $this->set(['contest_time_from' => 8000])->members()->count());
        $this->assertSame(5, $this->set(['contest_time_to' => 8000])->members()->count());
    }

    // -- a previa -----------------------------------------------------------

    /**
     * O ponto todo: enquanto o conjunto nao e aplicado, o run nao muda. A
     * equipe continua vendo o veredito que ja via e a classificacao nao se
     * mexe.
     */
    public function test_a_prepared_set_changes_nothing(): void
    {
        $run = $this->makeRun($this->problemA, $this->no);
        $rejudging = $this->set(['problem_id' => $this->problemA->id]);
        $this->shadowVerdict($rejudging, $run, $this->yes);

        $this->assertSame($this->no->id, $run->fresh()->answer_id);
        $this->assertFalse((bool) Score::where('user_id', $this->team->user_id)->first()->is_solved);
    }

    public function test_the_preview_counts_what_would_change(): void
    {
        $muda = $this->makeRun($this->problemA, $this->no);
        $naoMuda = $this->makeRun($this->problemA, $this->no);

        $rejudging = $this->set(['problem_id' => $this->problemA->id]);
        $this->shadowVerdict($rejudging, $muda, $this->yes);
        $this->shadowVerdict($rejudging, $naoMuda, $this->no);

        $preview = $this->service->preview($rejudging->fresh());

        $this->assertSame(2, $preview['total']);
        $this->assertSame(2, $preview['judged']);
        $this->assertSame(1, $preview['changes']);
    }

    /**
     * Um membro que falhou terminou, mas nao foi julgado. Conta-lo como
     * julgado faria a previa dizer "300 de 300 prontos" quando 40 sao erros.
     */
    public function test_a_failed_member_is_not_counted_as_judged_nor_as_a_change(): void
    {
        $run = $this->makeRun($this->problemA, $this->no);
        $rejudging = $this->set(['problem_id' => $this->problemA->id]);
        $this->shadowVerdict($rejudging, $run, $this->yes, error: 'nao compila mais');

        $preview = $this->service->preview($rejudging->fresh());

        $this->assertSame(0, $preview['judged']);
        $this->assertSame(1, $preview['errors']);
        $this->assertSame(0, $preview['changes']);
    }

    // -- aplicar e cancelar -------------------------------------------------

    public function test_applying_writes_the_new_verdicts_and_recomputes_the_score(): void
    {
        $run = $this->makeRun($this->problemA, $this->no);
        $rejudging = $this->set(['problem_id' => $this->problemA->id]);
        $this->shadowVerdict($rejudging, $run, $this->yes);

        $this->service->apply($rejudging->fresh(), $this->admin);

        $this->assertSame($this->yes->id, $run->fresh()->answer_id);
        $this->assertTrue((bool) Score::where('user_id', $this->team->user_id)->first()->is_solved);
    }

    /**
     * Hoje o rejulgamento de um run APAGA o veredito anterior, entao nao ha
     * antes/depois nenhum. Guardar o antigo e o que permite responder, seis
     * meses depois, o que aquele conjunto fez.
     */
    public function test_the_old_verdict_survives_the_apply(): void
    {
        $run = $this->makeRun($this->problemA, $this->no);
        $rejudging = $this->set(['problem_id' => $this->problemA->id]);
        $this->shadowVerdict($rejudging, $run, $this->yes);
        $this->service->apply($rejudging->fresh(), $this->admin);

        $member = RejudgingRun::where('rejudging_id', $rejudging->id)->first();

        $this->assertSame($this->no->id, (int) $member->old_answer_id);
        $this->assertSame($this->yes->id, (int) $member->new_answer_id);
    }

    public function test_cancelling_leaves_every_run_exactly_as_it_was(): void
    {
        $run = $this->makeRun($this->problemA, $this->no);
        $rejudging = $this->set(['problem_id' => $this->problemA->id]);
        $this->shadowVerdict($rejudging, $run, $this->yes);

        $this->service->cancel($rejudging->fresh(), $this->admin);

        $this->assertSame($this->no->id, $run->fresh()->answer_id);
        $this->assertSame(Rejudging::STATUS_CANCELLED, $rejudging->fresh()->status);
        $this->assertFalse((bool) Score::where('user_id', $this->team->user_id)->first()->is_solved);
    }

    /**
     * Trocar um veredito real por uma falha de infraestrutura seria a equipe
     * pagando por um defeito nosso -- a mesma razao pela qual o watchdog do
     * #45 nao pontua o CS que ele proprio cria.
     */
    public function test_a_failed_member_keeps_its_old_verdict_when_the_set_is_applied(): void
    {
        $ok = $this->makeRun($this->problemA, $this->no);
        $falho = $this->makeRun($this->problemA, $this->tle);

        $rejudging = $this->set(['problem_id' => $this->problemA->id]);
        $this->shadowVerdict($rejudging, $ok, $this->yes);
        $this->shadowVerdict($rejudging, $falho, null, error: 'sandbox indisponivel');

        $this->service->apply($rejudging->fresh(), $this->admin);

        $this->assertSame($this->yes->id, $ok->fresh()->answer_id);
        $this->assertSame($this->tle->id, $falho->fresh()->answer_id);
    }

    /**
     * O veredito novo nunca chega pre-aprovado: a assinatura que liberou o
     * anterior foi dada sobre OUTRO julgamento, possivelmente contra outros
     * casos de teste (#138).
     */
    public function test_applying_revokes_the_verification_of_every_run_it_touches(): void
    {
        $this->contest->update(['verification_required' => true]);
        $run = $this->makeRun($this->problemA, $this->no, ['verified_at' => now(), 'verified_by' => $this->admin->user_id]);

        $rejudging = $this->set(['problem_id' => $this->problemA->id]);
        $this->shadowVerdict($rejudging, $run, $this->yes);
        $this->service->apply($rejudging->fresh(), $this->admin);

        $this->assertNull($run->fresh()->verified_at);
    }

    // -- a maquina de estados -----------------------------------------------

    public function test_a_set_cannot_be_applied_before_every_member_is_judged(): void
    {
        $um = $this->makeRun($this->problemA, $this->no);
        $this->makeRun($this->problemA, $this->no);

        $rejudging = $this->set(['problem_id' => $this->problemA->id]);
        $this->shadowVerdict($rejudging, $um, $this->yes);

        $this->assertSame(Rejudging::STATUS_PREPARING, $rejudging->fresh()->status);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/rejudgings/{$rejudging->id}/apply")->assertStatus(422);

        $this->assertSame($this->no->id, $um->fresh()->answer_id);
    }

    public function test_a_set_cannot_be_applied_twice(): void
    {
        $run = $this->makeRun($this->problemA, $this->no);
        $rejudging = $this->set(['problem_id' => $this->problemA->id]);
        $this->shadowVerdict($rejudging, $run, $this->yes);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/rejudgings/{$rejudging->id}/apply")->assertStatus(200);
        $this->postJson("/api/rejudgings/{$rejudging->id}/apply")->assertStatus(422);
    }

    public function test_an_applied_set_cannot_be_cancelled(): void
    {
        $run = $this->makeRun($this->problemA, $this->no);
        $rejudging = $this->set(['problem_id' => $this->problemA->id]);
        $this->shadowVerdict($rejudging, $run, $this->yes);
        $this->service->apply($rejudging->fresh(), $this->admin);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/rejudgings/{$rejudging->id}/cancel")->assertStatus(422);
    }

    /**
     * Um filtro que nao pegou nada ja nasce decidivel. Deixa-lo em
     * `preparing` para sempre esconderia o engano atras de uma barra de
     * progresso que nunca anda.
     */
    public function test_an_empty_set_is_ready_immediately(): void
    {
        $rejudging = $this->set(['problem_id' => $this->problemB->id]);

        $this->assertSame(0, $rejudging->members()->count());
        $this->assertSame(Rejudging::STATUS_READY, $rejudging->fresh()->status);
    }

    // -- as portas ----------------------------------------------------------

    public function test_the_dry_run_counts_without_creating_anything(): void
    {
        $this->makeRun($this->problemA, $this->no);
        $this->makeRun($this->problemA, $this->yes);

        Sanctum::actingAs($this->admin);
        $body = $this->postJson("/api/contests/{$this->contest->id}/rejudgings/dry-run", [
            'problem_id' => $this->problemA->id,
        ])->assertStatus(200)->json();

        $this->assertSame(1, $body['matches']);
        $this->assertSame(1, $body['accepted_excluded'], 'a previa tem que dizer quantos aceitos ficaram de fora');
        $this->assertSame(0, Rejudging::count());
    }

    public function test_the_reason_is_required(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/contests/{$this->contest->id}/rejudgings", [
            'problem_id' => $this->problemA->id,
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_a_team_cannot_start_a_bulk_rejudge(): void
    {
        Sanctum::actingAs($this->team);

        $this->postJson("/api/contests/{$this->contest->id}/rejudgings", [
            'problem_id' => $this->problemA->id,
            'reason' => 'quero passar',
        ])->assertStatus(403);
    }

    // -- o congelamento -----------------------------------------------------

    /**
     * A decisao que a issue pede que se tome antes, e nao depois.
     *
     * Aplicar durante o congelamento MEXE nas celulas anteriores ao corte --
     * e deve mesmo: aqueles vereditos ja eram publicos, e corrigi-los e o
     * motivo de existir o rejulgamento. O que nao pode acontecer e vazar o
     * que o congelamento esconde, e nao acontece: o placar congelado so
     * conta runs anteriores ao corte (#211), entao um envio do congelamento
     * continua invisivel com veredito novo ou velho.
     */
    public function test_applying_during_the_freeze_does_not_reveal_a_frozen_submission(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(270)]);
        $this->contest->refresh();
        $this->assertTrue($this->contest->isFrozen());

        $congelado = $this->makeRun($this->problemA, $this->no, ['contest_time' => 260 * 60]);

        $rejudging = $this->set(['problem_id' => $this->problemA->id]);
        $this->shadowVerdict($rejudging, $congelado, $this->yes);
        $this->service->apply($rejudging->fresh(), $this->admin);

        $this->assertSame($this->yes->id, $congelado->fresh()->answer_id, 'o veredito novo tem que ser gravado');

        $cells = collect(Leaderboard::getScoreboard($this->contest->id, true))
            ->firstWhere('user.user_id', $this->team->user_id)['problems'];

        $this->assertFalse(
            collect($cells)->firstWhere('problem_id', $this->problemA->id)['is_solved'],
            'o placar congelado revelou um envio feito durante o congelamento'
        );
    }

    public function test_applying_during_the_freeze_does_correct_a_pre_freeze_verdict(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(270)]);
        $this->contest->refresh();

        $antigo = $this->makeRun($this->problemA, $this->no, ['contest_time' => 30 * 60]);

        $rejudging = $this->set(['problem_id' => $this->problemA->id]);
        $this->shadowVerdict($rejudging, $antigo, $this->yes);
        $this->service->apply($rejudging->fresh(), $this->admin);

        $cells = collect(Leaderboard::getScoreboard($this->contest->id, true))
            ->firstWhere('user.user_id', $this->team->user_id)['problems'];

        $this->assertTrue(
            collect($cells)->firstWhere('problem_id', $this->problemA->id)['is_solved'],
            'a correcao de um veredito anterior ao congelamento tem que aparecer'
        );
    }
}
