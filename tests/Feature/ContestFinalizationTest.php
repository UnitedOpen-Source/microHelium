<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Clarification;
use App\Models\Contest;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Score;
use App\Models\Site;
use App\Services\ContestAwards;
use App\Services\ContestFinalizer;
use Helium\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #202 -- finalizar a prova e derivar a premiacao.
 *
 * Finalizar e uma CHECAGEM DE INTEGRIDADE da prova inteira, e nao um botao
 * de publicar: e o momento em que a organizacao afirma que nao sobrou nada
 * pendente que pudesse mudar a classificacao. Cada condicao que os
 * requisitos de CCS listam tem o seu teste, e cada uma tem um teste
 * gemeo mostrando que ela SOME quando o problema e resolvido -- uma
 * condicao que nunca se satisfaz trava a prova em vez de proteger.
 */
class ContestFinalizationTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    private Problem $problem;

    private Answer $yes;

    private Answer $no;

    private Answer $cs;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Acabou e ja foi revelado: o ponto de partida em que finalizar e
        // legitimo, para que cada teste introduza UM impedimento.
        $this->contest = Contest::factory()->finished()->create(['unfrozen_at' => now()]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problem = Problem::factory()->create(['contest_id' => $this->contest->id]);
        $this->yes = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'YES', 'is_accepted' => true]);
        $this->no = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'NO', 'is_accepted' => false]);
        $this->cs = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'CS', 'is_accepted' => false]);
        $this->admin = $this->createTestUser(['user_type' => 'admin', 'contest_id' => $this->contest->id]);
    }

    private function team(string $name): User
    {
        return $this->createTestUser(['fullname' => $name, 'contest_id' => $this->contest->id, 'site_id' => $this->site->id]);
    }

    private function solve(User $team, Problem $problem, int $minute = 30, ?Answer $answer = null): Run
    {
        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'status' => 'judged',
            'answer_id' => ($answer ?? $this->yes)->id,
            'contest_time' => $minute * 60,
            'judged_time' => $minute * 60,
        ]);

        Score::updateScore($run);

        return $run;
    }

    /** @return list<string> */
    private function blockerCodes(): array
    {
        return array_column(app(ContestFinalizer::class)->blockers($this->contest->fresh()), 'code');
    }

    // -- o que impede -------------------------------------------------------

    public function test_a_clean_finished_contest_can_be_finalized(): void
    {
        $this->assertSame([], $this->blockerCodes());
    }

    public function test_a_running_contest_cannot_be_finalized(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(10), 'unfrozen_at' => now()]);

        $this->assertContains('contest_running', $this->blockerCodes());
    }

    public function test_an_unjudged_submission_blocks_finalizing(): void
    {
        $team = $this->team('Equipe');
        Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $team->user_id,
            'problem_id' => $this->problem->id,
            'status' => 'pending',
        ]);

        $this->assertContains('unjudged_submissions', $this->blockerCodes());
    }

    /**
     * CS e o veredito que a propria infraestrutura produz quando desiste
     * (#45). Finalizar com um de pe seria declarar final uma classificacao
     * que contem um envio que ninguem julgou.
     */
    public function test_a_judging_error_blocks_finalizing(): void
    {
        $this->solve($this->team('Equipe'), $this->problem, answer: $this->cs);

        $this->assertContains('judging_errors', $this->blockerCodes());
    }

    public function test_an_unanswered_clarification_blocks_finalizing(): void
    {
        Clarification::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $this->team('Equipe')->user_id,
            'status' => 'pending',
        ]);

        $this->assertContains('unanswered_clarifications', $this->blockerCodes());
    }

    /**
     * Este impedimento e nosso e nao do padrao, e a razao e concreta:
     * publicar "medalha de ouro: equipe X" enquanto o placar esta congelado
     * revela, pela lista de medalhas, que X esta entre os primeiros -- que e
     * exatamente o que o congelamento esconde.
     */
    public function test_a_frozen_contest_cannot_be_finalized(): void
    {
        $this->contest->update(['unfrozen_at' => null]);
        $this->assertTrue($this->contest->fresh()->isFrozen());

        $this->assertContains('still_frozen', $this->blockerCodes());
    }

    public function test_a_contest_cannot_be_finalized_twice(): void
    {
        app(ContestFinalizer::class)->finalize($this->contest, $this->admin);

        $this->assertContains('already_finalized', $this->blockerCodes());
    }

    /**
     * Cada impedimento tem que SAIR quando o problema e resolvido. Uma
     * condicao que nunca se satisfaz trava a prova em vez de proteger, e
     * esse defeito passa despercebido enquanto so se testa que ela bloqueia.
     */
    public function test_every_blocker_clears_once_its_cause_is_gone(): void
    {
        $team = $this->team('Equipe');

        $pendente = Run::factory()->create([
            'contest_id' => $this->contest->id, 'site_id' => $this->site->id,
            'user_id' => $team->user_id, 'problem_id' => $this->problem->id, 'status' => 'pending',
        ]);
        $comErro = $this->solve($team, $this->problem, answer: $this->cs);
        $clarificacao = Clarification::factory()->create([
            'contest_id' => $this->contest->id, 'site_id' => $this->site->id,
            'user_id' => $team->user_id, 'status' => 'pending',
        ]);

        $this->assertSame(
            ['unjudged_submissions', 'judging_errors', 'unanswered_clarifications'],
            $this->blockerCodes()
        );

        $pendente->update(['status' => 'judged', 'answer_id' => $this->no->id]);
        $comErro->update(['answer_id' => $this->no->id]);
        $clarificacao->update(['status' => 'answered']);

        $this->assertSame([], $this->blockerCodes());
    }

    // -- a porta ------------------------------------------------------------

    public function test_the_preflight_names_each_blocker(): void
    {
        $this->contest->update(['unfrozen_at' => null]);

        Sanctum::actingAs($this->admin);
        $body = $this->getJson("/api/contests/{$this->contest->id}/finalize/preflight")->assertStatus(200)->json();

        $this->assertFalse($body['can_finalize']);
        $this->assertContains('still_frozen', array_column($body['blockers'], 'code'));
        $this->assertNotEmpty($body['blockers'][0]['message'], 'um impedimento sem mensagem nao ajuda ninguem');
    }

    public function test_finalizing_refuses_and_says_why(): void
    {
        $this->contest->update(['unfrozen_at' => null]);

        Sanctum::actingAs($this->admin);
        $body = $this->postJson("/api/contests/{$this->contest->id}/finalize")->assertStatus(422)->json();

        $this->assertContains('still_frozen', array_column($body['blockers'], 'code'));
        $this->assertNull($this->contest->fresh()->finalized_at);
    }

    public function test_finalizing_records_who_and_when(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/contests/{$this->contest->id}/finalize")->assertStatus(200);

        $contest = $this->contest->fresh();

        $this->assertTrue($contest->isFinalized());
        $this->assertSame($this->admin->user_id, (int) $contest->finalized_by);
    }

    public function test_a_team_cannot_finalize(): void
    {
        Sanctum::actingAs($this->team('Equipe'));

        $this->postJson("/api/contests/{$this->contest->id}/finalize")->assertStatus(403);
        $this->getJson("/api/contests/{$this->contest->id}/awards")->assertStatus(403);
    }

    // -- a mediana ----------------------------------------------------------

    /**
     * "Teams that solved fewer problems than the median team are not ranked
     *  at all" -- e "fewer than", nao "at most". Quem empata com a mediana
     * continua classificado, e essa diferenca decide o destino da metade do
     * meio da tabela.
     */
    public function test_teams_below_the_median_are_not_ranked(): void
    {
        $problemas = [$this->problem];
        for ($i = 0; $i < 3; $i++) {
            $problemas[] = Problem::factory()->create(['contest_id' => $this->contest->id]);
        }

        // Resolvidos: 3, 2, 1, 0 -> mediana 1.5; classificam-se os de 3 e 2.
        $times = [];
        foreach ([3, 2, 1, 0] as $index => $quantidade) {
            $time = $this->team("Equipe {$index}");
            $times[] = $time;

            for ($p = 0; $p < $quantidade; $p++) {
                $this->solve($time, $problemas[$p], 10 + $p);
            }
        }

        $awards = app(ContestAwards::class)->forContest($this->contest->fresh());

        $this->assertSame(1.5, $awards['median_solved']);

        $mencao = collect($awards['awards'])->firstWhere('id', 'honorable-mention');
        $this->assertSame(
            [$times[2]->user_id, $times[3]->user_id],
            $mencao['team_ids'],
            'as equipes abaixo da mediana deveriam ficar com mencao honrosa'
        );

        $this->assertSame([$times[0]->user_id], collect($awards['awards'])->firstWhere('id', 'winner')['team_ids']);
    }

    /**
     * A resposta a pergunta que a issue deixa em aberto: sim, configuravel.
     * Uma prova de treino quer classificar todo mundo, e aplicar a mediana
     * la deixaria metade da turma sem colocacao por um motivo que nao existe
     * naquele contexto.
     */
    public function test_the_median_cut_can_be_turned_off(): void
    {
        $this->contest->update(['rank_median_cut' => false]);

        $forte = $this->team('Forte');
        $this->team('Fraco');
        $this->solve($forte, $this->problem);

        $awards = app(ContestAwards::class)->forContest($this->contest->fresh());

        $this->assertNull(collect($awards['awards'])->firstWhere('id', 'honorable-mention'));
        $this->assertFalse($awards['median_cut_applied']);
    }

    /**
     * Com o #212 as equipes sem nada julgado passaram a aparecer no placar.
     * Elas contam para a mediana: sao competidoras, e tira-las da conta
     * subiria a mediana e desclassificaria gente que a regra nao manda
     * desclassificar.
     */
    public function test_teams_that_solved_nothing_count_towards_the_median(): void
    {
        $forte = $this->team('Forte');
        $this->solve($forte, $this->problem);
        $this->team('Zerado A');
        $this->team('Zerado B');

        $awards = app(ContestAwards::class)->forContest($this->contest->fresh());

        $this->assertSame(0.0, $awards['median_solved'], 'tres equipes com 1, 0 e 0 tem mediana zero');
    }

    // -- as medalhas --------------------------------------------------------

    /**
     * Zero por padrao, e nao 4/4/4. Declarar medalhista e uma afirmacao para
     * fora, e um sistema que sai premiando ouro numa prova de treino porque
     * ninguem mexeu na configuracao e pior do que um que exige dizer
     * quantas.
     */
    public function test_no_medals_are_awarded_by_default(): void
    {
        $this->solve($this->team('Equipe'), $this->problem);

        $awards = app(ContestAwards::class)->forContest($this->contest->fresh());
        $ids = array_column($awards['awards'], 'id');

        $this->assertNotContains('gold-medal', $ids);
        $this->assertContains('winner', $ids, 'campeao nao e medalha: sempre existe se alguem se classificou');
    }

    public function test_medals_follow_the_configured_counts_in_rank_order(): void
    {
        $this->contest->update(['medal_gold' => 1, 'medal_silver' => 1, 'medal_bronze' => 1, 'rank_median_cut' => false]);

        $problemas = [$this->problem];
        for ($i = 0; $i < 3; $i++) {
            $problemas[] = Problem::factory()->create(['contest_id' => $this->contest->id]);
        }

        $times = [];
        foreach ([4, 3, 2, 1] as $index => $quantidade) {
            $time = $this->team("Equipe {$index}");
            $times[] = $time;

            for ($p = 0; $p < $quantidade; $p++) {
                $this->solve($time, $problemas[$p], 10 + $p);
            }
        }

        $awards = collect(app(ContestAwards::class)->forContest($this->contest->fresh())['awards']);

        $this->assertSame([$times[0]->user_id], $awards->firstWhere('id', 'gold-medal')['team_ids']);
        $this->assertSame([$times[1]->user_id], $awards->firstWhere('id', 'silver-medal')['team_ids']);
        $this->assertSame([$times[2]->user_id], $awards->firstWhere('id', 'bronze-medal')['team_ids']);
    }

    /**
     * Equipes empatadas compartilham a posicao, entao duas linhas podem ser
     * rank-1 -- e serem duas primeiras colocadas e o que aconteceu.
     */
    public function test_tied_teams_share_a_rank_award(): void
    {
        $a = $this->team('Empatada A');
        $b = $this->team('Empatada B');
        $this->solve($a, $this->problem, 30);
        $this->solve($b, $this->problem, 30);

        $awards = collect(app(ContestAwards::class)->forContest($this->contest->fresh())['awards']);

        $this->assertCount(2, $awards->firstWhere('id', 'rank-1')['team_ids']);
    }

    /**
     * Vem de scores.is_first_solver, que o #171 corrigiu para nao sobreviver
     * a um rejulgamento que tira o AC -- sem aquele conserto a premiacao
     * citaria uma equipe que nao resolveu mais.
     */
    public function test_first_to_solve_is_awarded_per_problem(): void
    {
        $primeiro = $this->team('Primeiro');
        $depois = $this->team('Depois');
        $this->solve($primeiro, $this->problem, 10);
        $this->solve($depois, $this->problem, 50);

        $awards = collect(app(ContestAwards::class)->forContest($this->contest->fresh())['awards']);

        $this->assertSame(
            [$primeiro->user_id],
            $awards->firstWhere('id', "first-to-solve-{$this->problem->id}")['team_ids']
        );
    }

    public function test_the_awards_say_whether_the_contest_is_final(): void
    {
        $this->solve($this->team('Equipe'), $this->problem);

        $this->assertFalse(app(ContestAwards::class)->forContest($this->contest->fresh())['finalized']);

        app(ContestFinalizer::class)->finalize($this->contest, $this->admin);

        $this->assertTrue(app(ContestAwards::class)->forContest($this->contest->fresh())['finalized']);
    }
}
