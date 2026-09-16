<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Problem;
use App\Models\Rejudging;
use App\Models\RejudgingRun;
use App\Models\Run;
use App\Models\Score;
use App\Models\Site;
use App\Services\RejudgingService;
use Helium\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Issue #192/#245 -- o rejulgamento em lote pela tela.
 *
 * O serviço existe desde o #192 e só era alcançável por chamada manual. O
 * cenário é de meio de prova: descobre-se que o caso de teste 7 do problema
 * C está errado, conserta-se o pacote, e todos os envios do C precisam
 * voltar para a fila — com o relógio andando e as equipes esperando.
 *
 * As regras são testadas em `RejudgingTest`, contra o serviço. O que se
 * verifica aqui é o que a TELA garante: que a conferência vem antes e não
 * grava nada, que o motivo é obrigatório, que os aceitos ficam de fora sem
 * pedido explícito, que a conversão de minuto para segundo é a certa, e que
 * aplicar e cancelar respeitam o estado do conjunto.
 */
class RejudgingScreenTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    private Problem $problemA;

    private Problem $problemB;

    private Answer $yes;

    private Answer $no;

    private User $team;

    private User $judge;

    private RejudgingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->contest = Contest::factory()->running(60)->create(['name' => 'Regional 2026']);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problemA = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'A']);
        $this->problemB = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'B']);
        $this->yes = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'YES', 'is_accepted' => true]);
        $this->no = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'NO', 'is_accepted' => false]);

        $this->team = $this->createTestUser(['contest_id' => $this->contest->id, 'site_id' => $this->site->id]);
        $this->judge = $this->createTestUser(['user_type' => 'judge', 'contest_id' => $this->contest->id]);

        $this->service = app(RejudgingService::class);
    }

    private function makeRun(Problem $problem, Answer $answer, int $contestTime = 600): Run
    {
        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $this->team->user_id,
            'problem_id' => $problem->id,
            'status' => 'judged',
            'answer_id' => $answer->id,
            'contest_time' => $contestTime,
            'judged_time' => $contestTime,
        ]);

        Score::updateScore($run);

        return $run;
    }

    /**
     * @param  array<string, mixed>  $campos
     */
    private function conferir(array $campos = []): TestResponse
    {
        return $this->actingAs($this->judge)
            ->from(route('judge.rejudgings'))
            ->post(route('judge.rejudgings.preview', $this->contest), $campos);
    }

    /**
     * @param  array<string, mixed>  $campos
     */
    private function montar(array $campos = []): TestResponse
    {
        return $this->actingAs($this->judge)
            ->from(route('judge.rejudgings'))
            ->post(route('judge.rejudgings.store', $this->contest), array_merge([
                'reason' => 'caso de teste 7 do problema A estava errado',
            ], $campos));
    }

    /**
     * Exatamente o que RejudgeMemberJob escreve, e nada mais.
     *
     * O job grava o veredito no membro e NÃO mexe no estado do conjunto:
     * quem recontá-lo é `refreshReadiness`. Chamar o serviço aqui esconderia
     * essa parte da tela.
     */
    private function shadowVerdict(Rejudging $rejudging, Run $run, ?Answer $answer, ?string $error = null): void
    {
        RejudgingRun::where('rejudging_id', $rejudging->id)->where('run_id', $run->id)->firstOrFail()->update([
            'new_answer_id' => $answer?->id,
            'new_verdict' => $answer?->short_name,
            'judged_at' => now(),
            'error' => $error,
        ]);
    }

    // -- acesso -------------------------------------------------------------

    /**
     * Uma equipe não chega a nenhuma das rotas: a prévia mostra o veredito
     * que CADA equipe teria, isto é, a classificação inteira antes de ela
     * existir.
     */
    public function test_a_team_reaches_none_of_it(): void
    {
        $this->get(route('judge.rejudgings'))->assertRedirect();

        $this->actingAs($this->team)->get(route('judge.rejudgings'))->assertForbidden();

        $this->actingAs($this->team)
            ->post(route('judge.rejudgings.preview', $this->contest), ['problem_id' => $this->problemA->id])
            ->assertForbidden();
    }

    /**
     * Juiz e não só admin, pela razão escrita na spec: quem pode rejulgar um
     * envio pode rejulgar o problema inteiro, e a diferença entre as duas
     * coisas é de escala, não de autoridade.
     */
    public function test_a_judge_reaches_the_screen(): void
    {
        $this->actingAs($this->judge)->get(route('judge.rejudgings'))->assertOk()->assertSee('Regional 2026');
    }

    public function test_the_screen_is_reachable_from_the_judging_page(): void
    {
        $this->actingAs($this->judge)
            ->get(route('judge.runs'))
            ->assertOk()
            ->assertSee(route('judge.rejudgings'));
    }

    // -- conferir antes de montar -------------------------------------------

    /**
     * Montar o conjunto dispara julgamento de verdade. Descobrir que o
     * critério pegou 4000 envios em vez de 40 depois de a fila já estar
     * cheia é tarde — então o primeiro botão só conta.
     */
    public function test_the_check_counts_without_creating_anything(): void
    {
        $this->makeRun($this->problemA, $this->no);
        $this->makeRun($this->problemA, $this->no);
        $this->makeRun($this->problemB, $this->no);

        $this->conferir(['problem_id' => $this->problemA->id])
            ->assertRedirect(route('judge.rejudgings'))
            ->assertSessionHas('previa', fn (array $p) => $p['matches'] === 2);

        $this->assertSame(0, Rejudging::count(), 'conferir não pode montar conjunto');
        $this->assertSame(0, RejudgingRun::count());
        Queue::assertNothingPushed();
    }

    /**
     * Quantos aceitos ficaram de fora é o número que permite decidir se é
     * isso mesmo que se queria: tirar um AC de uma equipe no meio da prova é
     * a mudança mais cara que um rejulgamento faz.
     */
    public function test_the_check_says_how_many_accepted_runs_it_left_out(): void
    {
        $this->makeRun($this->problemA, $this->yes);
        $this->makeRun($this->problemA, $this->yes);
        $this->makeRun($this->problemA, $this->no);

        $this->conferir(['problem_id' => $this->problemA->id])
            ->assertSessionHas('previa', function (array $p) {
                $this->assertSame(1, $p['matches']);
                $this->assertSame(2, $p['accepted_excluded']);
                $this->assertFalse($p['include_accepted']);

                return true;
            });

        $this->actingAs($this->judge)
            ->get(route('judge.rejudgings', ['contest_id' => $this->contest->id]))
            ->assertSee('ficaram de fora', false);
    }

    public function test_asking_for_accepted_runs_includes_them(): void
    {
        $this->makeRun($this->problemA, $this->yes);
        $this->makeRun($this->problemA, $this->no);

        $this->conferir(['problem_id' => $this->problemA->id, 'include_accepted' => '1'])
            ->assertSessionHas('previa', fn (array $p) => $p['matches'] === 2 && $p['accepted_excluded'] === 0);
    }

    /**
     * `runs.contest_time` é em SEGUNDOS e quem opera a prova fala em
     * minutos. Se a tela passasse 90 adiante como está, o filtro pegaria o
     * segundo 90 e selecionaria quase nada — em silêncio.
     */
    public function test_contest_minutes_become_seconds(): void
    {
        $this->makeRun($this->problemA, $this->no, contestTime: 300);   // minuto 5
        $this->makeRun($this->problemA, $this->no, contestTime: 5400);  // minuto 90
        $this->makeRun($this->problemA, $this->no, contestTime: 7200);  // minuto 120

        $this->conferir(['contest_minute_from' => 90, 'contest_minute_to' => 120])
            ->assertSessionHas('previa', function (array $p) {
                $this->assertSame(2, $p['matches'], 'minuto 90 e minuto 120');
                $this->assertSame(5400, $p['seconds_from'], 'a conversão volta visível');
                $this->assertSame(7200, $p['seconds_to']);

                return true;
            });

        // E a conta aparece escrita: uma conversão escondida é uma conversão
        // que ninguém confere.
        $this->actingAs($this->judge)
            ->get(route('judge.rejudgings', ['contest_id' => $this->contest->id]))
            ->assertSee('5400 s de prova', false);
    }

    /**
     * Um campo em branco significa "qualquer", e não "nenhum". Repassá-lo
     * como filtro faria a tela procurar envios sem problema, que não
     * existem, e a conferência diria zero sem dizer por quê.
     */
    public function test_an_empty_field_is_not_a_filter(): void
    {
        $this->makeRun($this->problemA, $this->no);
        $this->makeRun($this->problemB, $this->no);

        $this->conferir(['problem_id' => '', 'language_id' => '', 'site_id' => ''])
            ->assertSessionHas('previa', fn (array $p) => $p['matches'] === 2);
    }

    // -- montar --------------------------------------------------------------

    public function test_the_reason_is_required(): void
    {
        $this->makeRun($this->problemA, $this->no);

        $this->montar(['reason' => ''])
            ->assertRedirect(route('judge.rejudgings'))
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, Rejudging::count());
    }

    public function test_building_the_set_records_the_criterion_and_the_reason(): void
    {
        $this->makeRun($this->problemA, $this->no);
        $this->makeRun($this->problemB, $this->no);

        // Os campos em branco vão como o formulário os manda: um `select`
        // não escolhido envia string vazia, e não fica de fora do POST.
        $this->montar([
            'problem_id' => $this->problemA->id,
            'language_id' => '',
            'answer_id' => '',
            'site_id' => '',
            'judgehost_id' => '',
            'user_id' => '',
            'contest_minute_from' => '',
            'contest_minute_to' => '',
        ])->assertRedirect();

        $rejudging = Rejudging::firstOrFail();

        $this->assertSame('caso de teste 7 do problema A estava errado', $rejudging->reason);
        // O critério como foi PEDIDO, e não só a lista de envios que ele
        // selecionou: a lista diz o que foi feito, o critério diz por quê.
        // Exatamente o que foi pedido, e nada mais: um critério gravado com
        // `language_id: null` e `site_id: null` diria, seis meses depois,
        // que alguém escolheu esses campos.
        $this->assertSame(['problem_id' => $this->problemA->id], $rejudging->filters);
        $this->assertSame(1, $rejudging->members()->count());
        $this->assertSame($this->judge->user_id, $rejudging->created_by);
    }

    /**
     * Nada muda para as equipes enquanto o conjunto é julgado em sombra: o
     * veredito que elas viam continua lá e a classificação não se mexe.
     */
    public function test_building_the_set_does_not_touch_the_runs(): void
    {
        $run = $this->makeRun($this->problemA, $this->no);

        $this->montar(['problem_id' => $this->problemA->id]);

        $this->assertSame($this->no->id, $run->fresh()->answer_id);
        $this->assertSame('judged', $run->fresh()->status);
    }

    // -- decidir --------------------------------------------------------------

    /**
     * O julgamento de sombra roda em segundo plano e o job NÃO reconta o
     * conjunto -- ele grava o veredito no membro e vai embora. Sem a
     * recontagem na tela, o conjunto ficaria em "preparando" para sempre e o
     * botão de aplicar nunca apareceria: o serviço inteiro, inalcançável de
     * novo, só que agora atrás de uma barra de progresso que não anda.
     */
    public function test_opening_the_detail_page_notices_the_set_became_ready(): void
    {
        $run = $this->makeRun($this->problemA, $this->no);
        $this->montar(['problem_id' => $this->problemA->id]);
        $rejudging = Rejudging::firstOrFail();

        $this->shadowVerdict($rejudging, $run, $this->yes);

        $this->assertSame(Rejudging::STATUS_PREPARING, $rejudging->fresh()->status, 'controle: o job não reconta');

        $this->actingAs($this->judge)
            ->get(route('judge.rejudgings.show', $rejudging))
            ->assertOk()
            ->assertSee('Pronto para decidir');

        $this->assertSame(Rejudging::STATUS_READY, $rejudging->fresh()->status);
    }

    public function test_a_set_still_preparing_cannot_be_applied(): void
    {
        $this->makeRun($this->problemA, $this->no);
        $this->montar(['problem_id' => $this->problemA->id]);
        $rejudging = Rejudging::firstOrFail();

        $this->assertSame(Rejudging::STATUS_PREPARING, $rejudging->status);

        $this->actingAs($this->judge)
            ->from(route('judge.rejudgings.show', $rejudging))
            ->post(route('judge.rejudgings.apply', $rejudging))
            ->assertRedirect(route('judge.rejudgings.show', $rejudging))
            ->assertSessionHas('error', fn (string $erro) => str_contains($erro, 'preparing'));

        $this->assertSame(Rejudging::STATUS_PREPARING, $rejudging->fresh()->status);
    }

    public function test_applying_writes_the_new_verdicts(): void
    {
        $run = $this->makeRun($this->problemA, $this->no);
        $this->montar(['problem_id' => $this->problemA->id]);
        $rejudging = Rejudging::firstOrFail();
        $this->shadowVerdict($rejudging, $run, $this->yes);

        $this->actingAs($this->judge)
            ->from(route('judge.rejudgings.show', $rejudging))
            ->post(route('judge.rejudgings.apply', $rejudging))
            ->assertRedirect(route('judge.rejudgings.show', $rejudging))
            ->assertSessionHas('success');

        $this->assertSame($this->yes->id, $run->fresh()->answer_id);
        $this->assertSame(Rejudging::STATUS_APPLIED, $rejudging->fresh()->status);
    }

    public function test_cancelling_touches_no_verdict(): void
    {
        $run = $this->makeRun($this->problemA, $this->no);
        $this->montar(['problem_id' => $this->problemA->id]);
        $rejudging = Rejudging::firstOrFail();
        $this->shadowVerdict($rejudging, $run, $this->yes);

        $this->actingAs($this->judge)
            ->from(route('judge.rejudgings.show', $rejudging))
            ->post(route('judge.rejudgings.cancel', $rejudging))
            ->assertSessionHas('success');

        $this->assertSame($this->no->id, $run->fresh()->answer_id, 'cancelar não deixa rastro');
        $this->assertSame(Rejudging::STATUS_CANCELLED, $rejudging->fresh()->status);
    }

    public function test_an_applied_set_cannot_be_applied_again(): void
    {
        $run = $this->makeRun($this->problemA, $this->no);
        $this->montar(['problem_id' => $this->problemA->id]);
        $rejudging = Rejudging::firstOrFail();
        $this->shadowVerdict($rejudging, $run, $this->yes);
        $this->service->apply($rejudging->fresh(), $this->judge);

        $this->actingAs($this->judge)
            ->from(route('judge.rejudgings.show', $rejudging))
            ->post(route('judge.rejudgings.apply', $rejudging))
            ->assertSessionHas('error');

        $this->actingAs($this->judge)
            ->from(route('judge.rejudgings.show', $rejudging))
            ->post(route('judge.rejudgings.cancel', $rejudging))
            ->assertSessionHas('error');
    }

    // -- a prévia na tela ------------------------------------------------------

    public function test_the_detail_page_shows_what_would_change_and_what_failed(): void
    {
        $muda = $this->makeRun($this->problemA, $this->no);
        $igual = $this->makeRun($this->problemA, $this->no);
        $falha = $this->makeRun($this->problemA, $this->no);

        $this->montar(['problem_id' => $this->problemA->id]);
        $rejudging = Rejudging::firstOrFail();

        $this->shadowVerdict($rejudging, $muda, $this->yes);
        $this->shadowVerdict($rejudging, $igual, $this->no);
        $this->shadowVerdict($rejudging, $falha, null, 'sandbox indisponível');

        $html = $this->actingAs($this->judge)->get(route('judge.rejudgings.show', $rejudging))->assertOk()->getContent();

        $this->assertStringContainsString('caso de teste 7 do problema A estava errado', $html);
        // Um membro que falhou fica como estava: trocar um veredito real por
        // uma falha de infraestrutura seria a equipe pagando por defeito
        // nosso. A tela diz isso em vez de esconder o número.
        $this->assertStringContainsString('sandbox indisponível', $html);
        $this->assertStringContainsString('uma falha nossa não pode virar veredito da equipe', $html);
    }

    /**
     * Um critério que não pega nada precisa dizer isso em voz alta. Um
     * "0 envios" sem explicação é indistinguível de uma tela quebrada, e a
     * pessoa está no meio de uma prova.
     */
    public function test_a_criterion_that_matches_nothing_says_so(): void
    {
        $this->conferir(['problem_id' => $this->problemA->id]);

        $this->actingAs($this->judge)
            ->get(route('judge.rejudgings', ['contest_id' => $this->contest->id]))
            ->assertSee('Nenhum envio bate com este critério', false);
    }

    /**
     * Os seletores trazem só o que pertence à competição escolhida. Um
     * problema de outra competição no seletor deixaria montar um critério
     * que nunca pega nada, e o erro apareceria como "0 envios" sem dizer que
     * a causa foi escolher o problema errado.
     */
    public function test_the_filter_options_belong_to_the_chosen_contest(): void
    {
        $outra = Contest::factory()->create(['name' => 'Outra Competição']);
        Problem::factory()->create(['contest_id' => $outra->id, 'short_name' => 'Z', 'name' => 'Problema de Outra']);

        $this->actingAs($this->judge)
            ->get(route('judge.rejudgings', ['contest_id' => $this->contest->id]))
            ->assertOk()
            ->assertDontSee('Problema de Outra');
    }
}
