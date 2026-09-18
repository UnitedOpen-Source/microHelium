<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Judgehost;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Services\JudgehostSpeedFactor;
use App\Services\JudgeWorkQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #251 (#196, fase 2) -- o limite de tempo servido por máquina.
 *
 * A fase 1 mede e avisa. O limite continuava digitado à mão, igual para
 * todas as máquinas, e com judgehosts de velocidades diferentes a mesma
 * solução recebia TLE numa e AC noutra — a fase 1 punha isso na tela, e a
 * equipe que competiu já tinha levado o veredito.
 *
 * A forma escolhida é **teto e piso em torno do digitado**, e não
 * substituição: o valor do problema continua sendo a referência, e a medição
 * só o ajusta dentro de uma faixa. O #117/#130 fica preservado em espírito —
 * a divergência passa a ser compensada, mas limitada.
 *
 * Os números aqui são escolhidos para que o fator não possa passar por
 * coincidência: a máquina lenta é 3x a rápida, e o limite digitado é 10s, de
 * forma que 10, 15 e 20 são todos distinguíveis.
 */
class JudgehostSpeedFactorTest extends TestCase
{
    use RefreshDatabase;

    private Contest $contest;

    private Problem $problem;

    private Language $language;

    private Answer $aceito;

    private Judgehost $rapida;

    private Judgehost $lenta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create(['is_active' => true]);
        $this->problem = Problem::factory()->create(['contest_id' => $this->contest->id, 'time_limit' => 10]);
        $this->language = Language::factory()->create(['contest_id' => $this->contest->id]);
        $this->aceito = Answer::factory()->create([
            'contest_id' => $this->contest->id,
            'short_name' => 'YES',
            'is_accepted' => true,
        ]);

        [$this->rapida] = Judgehost::issue('rapida', null);
        [$this->lenta] = Judgehost::issue('lenta', null);
    }

    private function medicao(Judgehost $host, int $cpuMs, ?Answer $answer = null, ?Problem $problem = null): Run
    {
        return Run::factory()->create([
            'contest_id' => $this->contest->id,
            'problem_id' => ($problem ?? $this->problem)->id,
            'language_id' => $this->language->id,
            'judgehost_id' => $host->id,
            'answer_id' => ($answer ?? $this->aceito)->id,
            'status' => 'judged',
            'measured_cpu_ms' => $cpuMs,
        ]);
    }

    private function fator(Judgehost $host): float
    {
        return app(JudgehostSpeedFactor::class)->forHost($this->contest, $host->id);
    }

    private function limite(Judgehost $host, int $digitado = 10): int
    {
        return app(JudgehostSpeedFactor::class)->effectiveSeconds($this->contest, $digitado, $host->id);
    }

    // ---------------------------------------------------------------
    // O fator.
    // ---------------------------------------------------------------

    public function test_a_slower_machine_gets_a_larger_factor_and_a_faster_one_smaller(): void
    {
        $this->medicao($this->rapida, 300);
        $this->medicao($this->lenta, 900);

        $this->assertGreaterThan(1.0, $this->fator($this->lenta), 'a maquina lenta nao recebeu fator maior');
        $this->assertLessThan(1.0, $this->fator($this->rapida), 'a maquina rapida nao recebeu fator menor');
    }

    /**
     * A mediana das duas medianas é a base, então duas máquinas simétricas
     * saem em 1.5x e 0.5x -- e não uma em 1.0 e a outra em 3.0.
     */
    public function test_the_baseline_is_the_median_of_the_machines(): void
    {
        $this->medicao($this->rapida, 300);
        $this->medicao($this->lenta, 900);

        // mediana das medianas = (300 + 900) / 2 = 600
        $this->assertEqualsWithDelta(1.5, $this->fator($this->lenta), 0.001);
        $this->assertEqualsWithDelta(0.5, $this->fator($this->rapida), 0.001);
    }

    /**
     * Uma máquina que engasgou UMA vez não arrasta o fator dela: mediana, e
     * não média, pelo mesmo motivo escrito na fase 1.
     */
    public function test_one_slow_run_does_not_drag_the_factor(): void
    {
        foreach ([300, 300, 300, 30000] as $ms) {
            $this->medicao($this->rapida, $ms);
        }
        $this->medicao($this->lenta, 300);

        // Mediana da rapida com o engasgo: (300 + 300) / 2 = 300, e nao a
        // media de 7725.
        $this->assertEqualsWithDelta(1.0, $this->fator($this->rapida), 0.001);
    }

    /**
     * Máquina sem medição nenhuma recebe o neutro -- e não zero, que
     * zeraria o limite.
     */
    public function test_a_machine_with_no_measurement_is_neutral(): void
    {
        $this->assertSame(1.0, $this->fator($this->lenta));
        $this->assertSame(10, $this->limite($this->lenta), 'sem medicao o limite deixou de ser o digitado');
    }

    /**
     * Julgamento local, sem `judgehost_id`, também.
     */
    public function test_judging_with_no_machine_is_neutral(): void
    {
        $this->medicao($this->rapida, 300);
        $this->medicao($this->lenta, 900);

        $this->assertSame(
            10,
            app(JudgehostSpeedFactor::class)->effectiveSeconds($this->contest, 10, null),
            'o julgamento local recebeu ajuste de uma maquina que nao e ele'
        );
    }

    /**
     * Uma máquina só não é comparação.
     *
     * A razão dela contra si mesma é 1.0, e contar isso empurraria todo
     * fator para o neutro -- a mesma recusa que `JudgehostCalibration`
     * aplica ao montar a tela.
     */
    public function test_a_group_with_a_single_machine_is_not_a_comparison(): void
    {
        // Grupo COMPARAVEL: a rapida sai em 0.5.
        $this->medicao($this->rapida, 300);
        $this->medicao($this->lenta, 900);

        // Grupo SOLO, noutro problema: se contasse, entraria uma razao 1.0
        // na lista da rapida e a mediana dela saltaria de 0.5 para 0.75.
        //
        // A primeira versao deste teste tinha so o grupo solo, e nao
        // guardava nada: com ou sem a recusa o fator dava 1.0, porque a
        // razao de uma maquina contra si mesma e 1.0 de qualquer forma.
        $solo = Problem::factory()->create(['contest_id' => $this->contest->id, 'time_limit' => 10]);
        $this->medicao($this->rapida, 4242, problem: $solo);

        $this->assertEqualsWithDelta(
            0.5,
            $this->fator($this->rapida),
            0.001,
            'um grupo de uma maquina so entrou na conta e puxou o fator para o neutro'
        );
    }

    /**
     * Envio NÃO aceito não entra: um que estourou o limite mede o LIMITE, e
     * não a máquina.
     */
    public function test_a_rejected_run_does_not_count_as_a_measurement(): void
    {
        $tle = Answer::factory()->create([
            'contest_id' => $this->contest->id,
            'short_name' => 'TLE',
            'is_accepted' => false,
        ]);

        $this->medicao($this->rapida, 300);
        $this->medicao($this->lenta, 900, answer: $tle);

        $this->assertSame(1.0, $this->fator($this->lenta), 'um envio reprovado virou medicao de velocidade');
    }

    // ---------------------------------------------------------------
    // A faixa, que é o ponto da decisão.
    // ---------------------------------------------------------------

    /**
     * TRES maquinas, e nao duas, e isso nao e detalhe de fixture.
     *
     * Com duas, a base e a mediana de duas medianas -- que fica no meio das
     * duas -- e a razao NUNCA passa de 2, por construcao. A primeira versao
     * deste teste usava duas maquinas e nao guardava nada: a mutacao que
     * remove o teto passava limpa, porque o teto nunca era alcancado.
     *
     * Com tres, a base e a mediana do MEIO, e a lenta fica livre para
     * disparar: 10000/100 = 100x, que sem teto viraria 1000 segundos.
     */
    public function test_the_adjusted_limit_is_capped_at_the_ceiling(): void
    {
        [$terceira] = Judgehost::issue('terceira', null);

        $this->medicao($this->rapida, 100);
        $this->medicao($terceira, 100);
        $this->medicao($this->lenta, 10000);

        $this->assertGreaterThan(
            2.0,
            $this->fator($this->lenta),
            'o fator nem chegou ao teto: este teste nao exercita o que diz exercitar'
        );

        $this->assertSame(
            20,
            $this->limite($this->lenta),
            'o limite passou do dobro do digitado: a faixa nao esta segurando'
        );
    }

    public function test_the_adjusted_limit_is_held_at_the_floor(): void
    {
        [$terceira] = Judgehost::issue('terceira', null);

        $this->medicao($this->rapida, 10);
        $this->medicao($terceira, 10000);
        $this->medicao($this->lenta, 10000);

        $this->assertLessThan(
            0.5,
            $this->fator($this->rapida),
            'o fator nem chegou ao piso: este teste nao exercita o que diz exercitar'
        );

        $this->assertSame(
            5,
            $this->limite($this->rapida),
            'o limite caiu abaixo da metade do digitado'
        );
    }

    /**
     * Dentro da faixa, o ajuste acontece de verdade -- senão o teto e o piso
     * valeriam por nunca ajustar nada.
     */
    public function test_inside_the_band_the_limit_really_moves(): void
    {
        $this->medicao($this->rapida, 300);
        $this->medicao($this->lenta, 900);

        // fator 1.5 -> 10 * 1.5 = 15, dentro de [5, 20]
        $this->assertSame(15, $this->limite($this->lenta));
        $this->assertSame(5, $this->limite($this->rapida));
    }

    /**
     * Piso e teto em 1.0 desligam o ajuste e devolvem a fase 1: medir e
     * avisar, sem agir. É a saída para quem não quer que o veredito mude.
     */
    public function test_the_band_can_be_closed_to_switch_the_feature_off(): void
    {
        config([
            'judgehost.calibration.limit_floor' => 1.0,
            'judgehost.calibration.limit_ceiling' => 1.0,
        ]);

        $this->medicao($this->rapida, 300);
        $this->medicao($this->lenta, 900);

        $this->assertSame(10, $this->limite($this->lenta));
        $this->assertSame(10, $this->limite($this->rapida));
    }

    /**
     * O limite nunca desce a zero, mesmo com piso absurdo: zero segundos
     * reprovaria tudo, e `ulimit -t` conta em segundos inteiros.
     */
    public function test_the_limit_never_reaches_zero(): void
    {
        config(['judgehost.calibration.limit_floor' => 0.0]);

        $this->medicao($this->rapida, 1);
        $this->medicao($this->lenta, 100000);

        $this->assertGreaterThanOrEqual(1, $this->limite($this->rapida, digitado: 1));
    }

    /**
     * As DUAS portas servem o mesmo número.
     *
     * O judgehost remoto recebe o limite já ajustado no payload, porque não
     * consulta banco por desenho; o julgamento local passa pelo
     * `AutoJudgeService`. Se só uma das duas ajustasse, a mesma solução
     * teria limites diferentes conforme a porta -- que é exatamente a
     * divergência que esta issue existe para fechar.
     */
    public function test_the_served_payload_carries_the_same_adjusted_limit(): void
    {
        $this->medicao($this->rapida, 300);
        $this->medicao($this->lenta, 900);

        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'problem_id' => $this->problem->id,
            'language_id' => $this->language->id,
            'judgehost_id' => $this->lenta->id,
            'status' => 'judging',
            // O payload so e montado depois da reivindicacao, e
            // `lease_expires_at` sai de `claimed_at`.
            'claimed_at' => now(),
        ]);

        $payload = app(JudgeWorkQueue::class)->workPayload($run->fresh());

        $this->assertSame(
            15,
            (int) $payload['problem']['time_limit'],
            'o payload do judgehost remoto nao carrega o limite ajustado'
        );

        $this->assertSame(
            $this->limite($this->lenta),
            (int) $payload['problem']['time_limit'],
            'as duas portas discordam sobre o limite da mesma maquina'
        );
    }

    /**
     * O ajuste acontece UMA VEZ, na entrega do trabalho.
     *
     * Este teste existia como "a porta local também ajusta", e estava
     * errado. Tentei aplicar o fator em `AutoJudgeService::executeProgram()`
     * também, e três medições mostraram que não deve:
     *
     *   1. o julgamento LOCAL nunca tem `judgehost_id` -- o daemon chama
     *      `claimNextLocally(null)`, e a coluna fica nula --, então o fator
     *      seria sempre 1.0 e a linha, código morto;
     *   2. no judgehost REMOTO o limite já chegou ajustado no payload, e
     *      ajustar de novo aplicaria o fator duas vezes;
     *   3. ler `$run->contest` ali é consulta preguiçosa, e o judgehost não
     *      tem credencial de banco (#116) -- `JudgeWithoutDatabaseTest`
     *      derrubou com "Judging reached the database".
     *
     * O que fica guardado é a propriedade que importa: quem NÃO tem banco
     * recebe o valor intacto, porque ele já veio ajustado.
     */
    public function test_a_caller_without_a_database_gets_the_limit_untouched(): void
    {
        $this->medicao($this->rapida, 300);
        $this->medicao($this->lenta, 900);

        $this->assertSame(
            10,
            app(JudgehostSpeedFactor::class)->effectiveSeconds(null, 10, $this->lenta->id),
            'o judgehost remoto aplicaria o fator uma segunda vez'
        );
    }

    /**
     * E o julgamento local, que não tem máquina, recebe o digitado -- é o
     * caso que tornava a fiação no AutoJudgeService código morto.
     */
    public function test_local_judging_has_no_machine_and_so_no_adjustment(): void
    {
        $this->medicao($this->rapida, 300);
        $this->medicao($this->lenta, 900);

        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'problem_id' => $this->problem->id,
            'language_id' => $this->language->id,
            'status' => 'pending',
        ]);

        $this->assertNull(
            app(JudgeWorkQueue::class)->claimNextLocally()?->judgehost_id,
            'o julgamento local passou a carimbar judgehost_id, e o ajuste precisa ser revisto'
        );

        // E por isso o fator de um julgamento sem maquina e o neutro: nao ha
        // maquina a quem comparar.
        $this->assertSame(
            10,
            app(JudgehostSpeedFactor::class)->effectiveSeconds($this->contest, 10, null),
            'um julgamento sem maquina recebeu ajuste'
        );

        $run->delete();
    }

    /**
     * E o payload de uma máquina sem medição carrega o digitado -- senão o
     * teste acima passaria por servir sempre o mesmo número ajustado.
     */
    public function test_the_payload_of_an_unmeasured_machine_carries_the_typed_limit(): void
    {
        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'problem_id' => $this->problem->id,
            'language_id' => $this->language->id,
            'judgehost_id' => $this->lenta->id,
            'status' => 'judging',
            // O payload so e montado depois da reivindicacao, e
            // `lease_expires_at` sai de `claimed_at`.
            'claimed_at' => now(),
        ]);

        $payload = app(JudgeWorkQueue::class)->workPayload($run->fresh());

        $this->assertSame(10, (int) $payload['problem']['time_limit']);
    }

    /**
     * O fator de um host é a mediana das razões dele em TODOS os grupos, e
     * não a do último problema medido.
     */
    public function test_the_factor_spans_every_comparable_group(): void
    {
        $outro = Problem::factory()->create(['contest_id' => $this->contest->id, 'time_limit' => 10]);

        // Problema 1: lenta e 3x. Problema 2: lenta e 1x.
        $this->medicao($this->rapida, 300);
        $this->medicao($this->lenta, 900);
        $this->medicao($this->rapida, 500, problem: $outro);
        $this->medicao($this->lenta, 500, problem: $outro);

        // Razoes da lenta: 1.5 e 1.0 -> mediana 1.25
        $this->assertEqualsWithDelta(1.25, $this->fator($this->lenta), 0.001);
    }
}
