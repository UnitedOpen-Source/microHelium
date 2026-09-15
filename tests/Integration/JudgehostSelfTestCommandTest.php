<?php

namespace Tests\Integration;

use Tests\Concerns\RequiresJudgeSandbox;
use Tests\TestCase;

/**
 * Issue #194 -- o autoteste que a organizacao roda na maquina de julgamento.
 *
 * Duas metades, e elas rodam em lugares diferentes de proposito:
 *
 * - A recusa com AUTOJUDGE_USE_BWRAP desligado roda em qualquer maquina,
 *   porque e a informacao mais importante que o comando pode dar e nao pode
 *   depender de existir sandbox para ser verificada.
 * - O autoteste completo so roda onde ha sandbox usavel. Em maquina de
 *   desenvolvedor ele se pula; no job Judging do CI, que roda dentro da
 *   imagem do juiz com --fail-on-skipped, pular e falhar -- que e como uma
 *   regressao de confinamento vira build vermelho em vez de silencio.
 */
class JudgehostSelfTestCommandTest extends TestCase
{
    use RequiresJudgeSandbox;

    /**
     * Uma instalacao que suba com o bwrap desligado julga codigo submetido
     * sem confinamento nenhum e nao avisa ninguem. O comando existe
     * sobretudo para isso.
     */
    public function test_it_refuses_outright_when_the_sandbox_is_turned_off(): void
    {
        config(['autojudge.use_bwrap' => false]);

        $this->artisan('judgehost:selftest')
            ->expectsOutputToContain('AUTOJUDGE_USE_BWRAP esta DESLIGADO')
            ->expectsOutputToContain('Nao use em prova')
            ->assertExitCode(1);
    }

    /**
     * A recusa vem ANTES de qualquer caso: nenhum resultado e impresso, e
     * nenhum diretorio de trabalho e criado. Um comando que listasse casos
     * verdes e so depois reclamasse do bwrap desligado seria pior do que
     * nenhum comando.
     */
    public function test_the_refusal_comes_before_any_case_is_reported(): void
    {
        config(['autojudge.use_bwrap' => false]);

        $this->artisan('judgehost:selftest')
            ->doesntExpectOutputToContain('Fork bomb')
            ->doesntExpectOutputToContain('casos passaram')
            ->assertExitCode(1);
    }

    /**
     * O comando roda de ponta a ponta nesta maquina e todos os casos passam.
     *
     * Este e o teste que vale: ele exercita o wrapWithBwrap() de verdade,
     * contra o kernel de verdade. Se um dia esta maquina deixar de confinar,
     * e aqui que aparece.
     */
    public function test_every_case_passes_on_a_machine_that_confines(): void
    {
        $this->skipUnlessJudgeSandboxAvailable();
        $this->enableJudgeSandbox();

        $this->artisan('judgehost:selftest')
            ->expectsOutputToContain('Esta maquina pode julgar')
            ->assertExitCode(0);
    }

    /**
     * A saida em JSON e o que se cola num checklist, e o campo `passed` e o
     * que um script le. Uma saida bonita com `passed` errado nao serve.
     */
    public function test_the_json_output_carries_every_case_and_the_verdict(): void
    {
        $this->skipUnlessJudgeSandboxAvailable();
        $this->enableJudgeSandbox();

        $this->artisan('judgehost:selftest --json')->assertExitCode(0);

        // artisan() nao devolve a saida crua, entao o JSON e reconstruido
        // rodando o comando pelo kernel do console com um buffer proprio.
        $output = new \Symfony\Component\Console\Output\BufferedOutput;
        $exitCode = $this->app[\Illuminate\Contracts\Console\Kernel::class]
            ->call('judgehost:selftest', ['--json' => true], $output);

        $payload = json_decode($output->fetch(), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['passed']);

        $keys = array_column($payload['cases'], 'key');

        foreach (['fork_bomb', 'memory', 'disk', 'network', 'secrets', 'cpu_time', 'wall_time'] as $expected) {
            $this->assertContains($expected, $keys, "o caso {$expected} nao foi avaliado");
        }

        foreach ($payload['cases'] as $case) {
            $this->assertTrue($case['passed'], "caso {$case['key']}: {$case['detail']}");
        }
    }
}
