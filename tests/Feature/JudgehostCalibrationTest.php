<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Judgehost;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Services\JudgehostCalibration;
use Helium\User;
use Tests\TestCase;

/**
 * Issue #196, fase 1 -- "as minhas maquinas sao comparaveis?".
 *
 * O #117/#130 decidiu de proposito que hardware heterogeneo e AVISADO e nao
 * compensado. A decisao e honesta e deixava um buraco: nao havia nem o
 * aviso. Com maquinas de velocidades diferentes -- o caso quando
 * instituicoes parceiras emprestam o que tem (#53) -- a equipe recebe TLE ou
 * AC dependendo de qual maquina pegou o run, e nada dizia isso.
 *
 * Nada aqui muda veredito. O que estes testes protegem e que a comparacao
 * seja honesta: que ela nao compare o que nao da para comparar, e que nao
 * mostre "tudo bem" quando o que houve foi ausencia de medicao.
 */
class JudgehostCalibrationTest extends TestCase
{
    private Contest $contest;

    private Problem $problem;

    private Language $language;

    private Answer $yes;

    private Answer $tle;

    private Judgehost $rapida;

    private Judgehost $lenta;

    private User $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->running(60)->create();
        $site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problem = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'A']);
        $this->language = Language::factory()->create(['contest_id' => $this->contest->id]);
        $this->yes = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'YES', 'is_accepted' => true]);
        $this->tle = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'TLE', 'is_accepted' => false]);
        $this->team = $this->createTestUser(['contest_id' => $this->contest->id, 'site_id' => $site->id]);

        $this->rapida = Judgehost::create(['name' => 'rapida', 'token_hash' => str_repeat('a', 64), 'enabled' => true]);
        $this->lenta = Judgehost::create(['name' => 'lenta', 'token_hash' => str_repeat('b', 64), 'enabled' => true]);
    }

    private function judged(Judgehost $host, int $cpuMs, ?Answer $answer = null, ?Problem $problem = null): Run
    {
        return Run::factory()->judged($answer ?? $this->yes)->create([
            'contest_id' => $this->contest->id,
            'user_id' => $this->team->user_id,
            'problem_id' => ($problem ?? $this->problem)->id,
            'language_id' => $this->language->id,
            'judgehost_id' => $host->id,
            'measured_cpu_ms' => $cpuMs,
            'measured_wall_ms' => $cpuMs,
        ]);
    }

    private function report(): array
    {
        return app(JudgehostCalibration::class)->forContest($this->contest->fresh());
    }

    public function test_it_reports_how_many_times_slower_the_slowest_machine_is(): void
    {
        $this->judged($this->rapida, 1000);
        $this->judged($this->lenta, 3000);

        $item = $this->report()['items'][0];

        $this->assertSame(3.0, $item['divergence'], 'a maquina lenta demora tres vezes mais');
        $this->assertSame(1000, $item['fastest_ms']);
        $this->assertSame(3000, $item['slowest_ms']);
    }

    /**
     * Uma maquina so nao e uma comparacao. Devolver uma linha com
     * divergencia 1.0 encheria a tela de ruido tranquilizador: "nenhuma
     * divergencia" quando o que houve foi nenhuma medicao de comparacao.
     */
    public function test_one_machine_alone_produces_no_row(): void
    {
        $this->judged($this->rapida, 1000);
        $this->judged($this->rapida, 1200);

        $this->assertSame([], $this->report()['items']);
    }

    /**
     * Um envio que estourou o limite mede o LIMITE e nao a maquina.
     * Inclui-lo faria toda maquina parecer igualmente lenta, no valor do
     * time limit.
     */
    public function test_a_run_that_hit_the_limit_is_not_a_measurement_of_the_machine(): void
    {
        $this->judged($this->rapida, 1000);
        $this->judged($this->lenta, 5000, $this->tle);

        $this->assertSame([], $this->report()['items'], 'um TLE nao pode entrar na comparacao');
    }

    public function test_a_run_with_no_measurement_is_skipped(): void
    {
        $this->judged($this->rapida, 1000);
        Run::factory()->judged($this->yes)->create([
            'contest_id' => $this->contest->id,
            'user_id' => $this->team->user_id,
            'problem_id' => $this->problem->id,
            'language_id' => $this->language->id,
            'judgehost_id' => $this->lenta->id,
            'measured_cpu_ms' => null,
        ]);

        $this->assertSame([], $this->report()['items']);
    }

    /**
     * Mediana e nao media: uma maquina que engasgou uma vez arrasta a media
     * e nao arrasta a mediana, e o que se quer saber e como ela se comporta
     * em geral.
     */
    public function test_one_hiccup_does_not_decide_the_verdict_about_a_machine(): void
    {
        foreach ([1000, 1000, 1000, 60000] as $ms) {
            $this->judged($this->rapida, $ms);
        }
        $this->judged($this->lenta, 2000);

        $item = $this->report()['items'][0];

        $this->assertSame(1000, $item['fastest_ms'], 'o engasgo de 60s nao pode virar a mediana');
        $this->assertSame(2.0, $item['divergence']);
    }

    /**
     * Problemas e linguagens diferentes sao comparacoes diferentes: o mesmo
     * par de maquinas pode divergir muito em C++ e pouco em Python, e
     * misturar tudo numa media esconde exatamente isso.
     */
    public function test_each_problem_and_language_is_compared_on_its_own(): void
    {
        $outro = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'B']);

        $this->judged($this->rapida, 1000);
        $this->judged($this->lenta, 4000);
        $this->judged($this->rapida, 1000, null, $outro);
        $this->judged($this->lenta, 1100, null, $outro);

        $items = $this->report()['items'];

        $this->assertCount(2, $items);
        // O pior primeiro: quem abre a tela quer saber se ha um problema.
        $this->assertSame('A', $items[0]['problem']);
        $this->assertSame(4.0, $items[0]['divergence']);
        $this->assertSame(1.1, $items[1]['divergence']);
    }

    /**
     * O pior primeiro, mesmo quando ele NAO foi o primeiro a aparecer.
     *
     * Este teste existe separado do anterior porque la o pior caso era
     * tambem o primeiro criado, entao a ordenacao saia certa por acidente --
     * uma mutacao mostrou que apagar o usort() nao derrubava nada. Quem abre
     * esta tela quer saber se ha um problema, e nao ler uma lista em ordem
     * de id.
     */
    public function test_the_worst_divergence_comes_first_even_when_it_was_found_last(): void
    {
        $depois = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'Z']);

        // O problema A diverge pouco...
        $this->judged($this->rapida, 1000);
        $this->judged($this->lenta, 1100);
        // ...e o Z, criado depois, diverge muito.
        $this->judged($this->rapida, 1000, null, $depois);
        $this->judged($this->lenta, 5000, null, $depois);

        $items = $this->report()['items'];

        $this->assertSame('Z', $items[0]['problem'], 'a pior divergencia tem que vir primeiro');
        $this->assertSame(5.0, $items[0]['divergence']);
    }

    public function test_the_endpoint_answers_for_an_admin(): void
    {
        $this->judged($this->rapida, 1000);
        $this->judged($this->lenta, 2500);

        $body = $this->actingAs($this->createTestUser(['user_type' => 'admin']))
            ->getJson('/api/frontend/judgehosts/calibration')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(1.5, $body['threshold']);
        $this->assertSame(2.5, $body['items'][0]['divergence']);
    }

    public function test_a_team_cannot_read_the_calibration(): void
    {
        $this->actingAs($this->team)
            ->getJson('/api/frontend/judgehosts/calibration')
            ->assertStatus(403);
    }

    /**
     * Sem contest ativo a tela responde vazia em vez de quebrar -- e a mesma
     * escolha que o resto da superficie /api/frontend faz.
     */
    public function test_with_no_active_contest_it_answers_empty(): void
    {
        $this->contest->update(['is_active' => false]);

        $body = $this->actingAs($this->createTestUser(['user_type' => 'admin']))
            ->getJson('/api/frontend/judgehosts/calibration')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame([], $body['items']);
    }
}
