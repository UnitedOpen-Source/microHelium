<?php

namespace Tests\Integration;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
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
     *
     * Artisan::call() + output() e nao o helper artisan():
     * expectsOutputToContain() casa contra LINHAS, em ordem, e cada
     * expectativa consome a linha em que casou -- duas expectativas sobre a
     * mesma linha nunca passam as duas. A primeira versao deste teste
     * falhava por isso e a mensagem ("Output does not contain ...") apontava
     * para um texto que estava visivelmente na saida.
     */
    public function test_it_refuses_outright_when_the_sandbox_is_turned_off(): void
    {
        config(['autojudge.use_bwrap' => false]);

        $exitCode = Artisan::call('judgehost:selftest');
        $raw = Artisan::output();

        $this->assertSame(1, $exitCode, $raw);
        $this->assertStringContainsString('AUTOJUDGE_USE_BWRAP esta DESLIGADO', $raw);
        $this->assertStringContainsString('Nao use em prova', $raw);
    }

    /**
     * A recusa vem ANTES de qualquer caso de confinamento: nenhum trecho
     * hostil e executado, e nada verde e impresso. Um comando que listasse
     * casos verdes e so depois reclamasse do bwrap desligado seria pior do
     * que nenhum comando.
     */
    public function test_no_confinement_case_runs_when_the_sandbox_is_off(): void
    {
        config(['autojudge.use_bwrap' => false]);

        Artisan::call('judgehost:selftest');
        $raw = Artisan::output();

        $this->assertStringNotContainsString('Fork bomb', $raw);
        $this->assertStringNotContainsString('Alocacao sem teto', $raw);
        $this->assertStringNotContainsString('casos passaram', $raw);
    }

    /**
     * A recusa tambem tem que ser JSON quando se pediu JSON.
     *
     * Um script que recebesse texto solto aqui nao conseguiria distinguir
     * "esta maquina nao confina" de "o comando quebrou", e as duas coisas
     * pedem reacoes diferentes. Este teste roda em qualquer maquina, o que
     * importa: e o mesmo defeito que derrubou o job Judging duas vezes --
     * saida em modo de maquina que nenhuma maquina consegue ler.
     */
    public function test_the_refusal_is_valid_json_in_json_mode(): void
    {
        config(['autojudge.use_bwrap' => false]);

        $exitCode = Artisan::call('judgehost:selftest', ['--json' => true]);
        $raw = Artisan::output();

        $payload = json_decode($raw, true);

        $this->assertIsArray($payload, "a recusa em --json nao foi JSON valido:\n".$raw);
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['passed']);
        $this->assertSame(['sandbox_enabled'], array_column($payload['cases'], 'key'));
    }

    /**
     * E em modo JSON nao sai NADA alem do JSON.
     *
     * O cabecalho humano era impresso antes de o modo ser consultado, entao
     * a saida era um titulo, uma linha em branco e so entao o documento --
     * e json_decode, ou `jq`, devolvia null sobre o conjunto. Um formato de
     * maquina com uma saudacao em cima nao e um formato de maquina.
     */
    public function test_json_mode_prints_no_human_header(): void
    {
        config(['autojudge.use_bwrap' => false]);

        Artisan::call('judgehost:selftest', ['--json' => true]);
        $raw = Artisan::output();

        $this->assertStringStartsWith('{', trim($raw));
        $this->assertStringNotContainsString('Autoteste do sandbox de julgamento', $raw);
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

        // Artisan::call()/output() e nao artisan(): o helper de teste devolve
        // um PendingCommand para asserções sobre a saida, nao a saida crua, e
        // e a saida crua que precisa ser JSON valido -- a primeira versao
        // deste teste leu de um BufferedOutput passado ao kernel e recebeu
        // null do json_decode no CI.
        $exitCode = Artisan::call('judgehost:selftest', ['--json' => true]);
        $raw = Artisan::output();

        $payload = json_decode($raw, true);

        $this->assertIsArray($payload, "a saida --json nao foi JSON valido (exit {$exitCode}, ".strlen($raw)." bytes):\n".$raw);
        $this->assertSame(0, $exitCode, $raw);
        $this->assertTrue($payload['passed'], $raw);

        $keys = array_column($payload['cases'], 'key');

        foreach (['fork_bomb', 'memory', 'disk', 'network', 'secrets', 'cpu_time', 'wall_time'] as $expected) {
            $this->assertContains($expected, $keys, "o caso {$expected} nao foi avaliado");
        }

        foreach ($payload['cases'] as $case) {
            $this->assertTrue($case['passed'], "caso {$case['key']}: {$case['detail']}");
        }
    }
}
