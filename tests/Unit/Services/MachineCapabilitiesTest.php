<?php

namespace Tests\Unit\Services;

use App\Models\Language;
use App\Services\Judgehost\MachineCapabilities;
use Tests\TestCase;

/**
 * Issue #117 -- what a machine can run is found out, not configured.
 *
 * A configured list is a list someone has to remember to update, and the
 * day it is wrong a host repeatedly takes work it cannot do and hands it
 * straight back. These probe against the real shell, because the thing
 * being tested is precisely whether the probe agrees with reality.
 */
class MachineCapabilitiesTest extends TestCase
{
    private function language(string $extension, ?string $compile, ?string $run): object
    {
        return (object) [
            'extension' => $extension,
            'compile_command' => $compile,
            'run_command' => $run,
        ];
    }

    /**
     * The shape the agent actually hands it.
     *
     * JudgehostClient::languages() returns decoded JSON -- associative
     * arrays -- and the first version of this class read properties off
     * them. Every language was skipped, the agent declared nothing, and
     * because "declared nothing" legitimately means "can judge anything",
     * capability routing silently did nothing at all. The unit tests
     * passed, because they all used (object) casts. An end-to-end run
     * against a real server is what caught it.
     */
    public function test_it_reads_the_decoded_json_the_client_actually_returns(): void
    {
        $fromApi = json_decode('[{"extension":"sh","compile_command":"true","run_command":"sh {source}"}]', true);

        $this->assertSame(['sh'], (new MachineCapabilities)->detect($fromApi));
    }

    public function test_a_language_whose_toolchain_is_present_is_reported(): void
    {
        $detected = (new MachineCapabilities)->detect([
            // sh exists everywhere this runs, including CI.
            $this->language('sh', null, 'sh {source}'),
        ]);

        $this->assertSame(['sh'], $detected);
    }

    public function test_a_language_whose_toolchain_is_missing_is_not_reported(): void
    {
        $detected = (new MachineCapabilities)->detect([
            $this->language('zz', null, 'definitely-not-a-real-compiler-zz {source}'),
        ]);

        $this->assertSame([], $detected);
    }

    public function test_a_language_needs_every_command_it_uses(): void
    {
        // Half a toolchain is not a toolchain: claiming this would make the
        // host take the work and fail at compile time.
        $detected = (new MachineCapabilities)->detect([
            $this->language('half', 'definitely-not-a-real-compiler-zz {source}', 'sh {source}'),
        ]);

        $this->assertSame([], $detected);
    }

    public function test_environment_prefixes_and_placeholders_are_not_mistaken_for_the_binary(): void
    {
        // Command templates start with things that are not the program:
        // GOCACHE=... go build ..., and {judge_runtime} placeholders the
        // judge substitutes later.
        $detected = (new MachineCapabilities)->detect([
            $this->language('env', null, 'HOME={judge_runtime} TMPDIR=/tmp sh {source}'),
        ]);

        $this->assertSame(['env'], $detected);
    }

    public function test_a_language_with_no_commands_at_all_is_left_undecided(): void
    {
        // Nothing was checked, so claiming the ability would be a guess.
        // The give-back path (#125) covers it instead.
        $detected = (new MachineCapabilities)->detect([
            $this->language('none', null, null),
        ]);

        $this->assertSame([], $detected);
    }

    public function test_the_same_extension_is_reported_once(): void
    {
        $detected = (new MachineCapabilities)->detect([
            $this->language('sh', null, 'sh {source}'),
            $this->language('sh', null, 'sh {source}'),
        ]);

        $this->assertSame(['sh'], $detected);
    }

    /**
     * Issue #354 -- o artefato nao e o programa.
     *
     * O `run_command` de toda linguagem compilada e literalmente
     * `./{executable}`: o binario que a etapa de compilacao acabou de
     * produzir, e que por definicao nao existe em maquina nenhuma antes de
     * a submissao chegar. Sondar esse token e perguntar por um arquivo que
     * nunca vai estar la, e como `detect()` exige que TODOS os binarios de
     * uma linguagem existam, a linguagem inteira era recusada.
     *
     * A pergunta certa para uma compilada ja estava sendo feita no outro
     * comando: existe o compilador?
     */
    public function test_uma_linguagem_compilada_e_declarada_pelo_compilador(): void
    {
        $detected = (new MachineCapabilities)->detect([
            $this->language('c', 'sh -c true {source}', './{executable}'),
        ]);

        $this->assertSame(['c'], $detected);
    }

    public function test_uma_linguagem_compilada_sem_o_compilador_nao_e_declarada(): void
    {
        // Controle negativo, e ele e o que torna o teste acima util: sem
        // este, "declarar tudo sempre" tambem passaria.
        $detected = (new MachineCapabilities)->detect([
            $this->language('zz', 'definitely-not-a-real-compiler-zz {source}', './{executable}'),
        ]);

        $this->assertSame([], $detected);
    }

    public function test_um_placeholder_no_meio_do_comando_nao_vira_o_binario_sondado(): void
    {
        // Descartar o `./{executable}` e seguir lendo daria o token
        // seguinte -- um `<`, um `{input}`, o que viesse depois. Quando o
        // comando comeca por um placeholder, nao ha programa instalado a
        // sondar, e a resposta e "nao sei", nao "o proximo token".
        $detected = (new MachineCapabilities)->detect([
            $this->language('redir', null, './{executable} < {input}'),
        ]);

        $this->assertSame([], $detected);
    }

    /**
     * O catalogo inteiro, contra uma maquina que tem tudo.
     *
     * Esta e a rede que faltava: ela nao pergunta se o toolchain esta
     * instalado aqui -- pergunta se a sonda sabe o que perguntar. Por isso
     * o `exists()` e substituido; o que esta sob teste e QUAL binario cada
     * linguagem faz a maquina procurar, e nao se o shell o encontra (isso
     * os outros casos deste arquivo ja medem contra o shell de verdade).
     *
     * Antes da #354 este teste reprovava 22 vezes -- todas as compiladas.
     */
    public function test_o_catalogo_inteiro_e_declarado_por_uma_maquina_que_tem_os_toolchains(): void
    {
        $machine = new class extends MachineCapabilities
        {
            /** @var list<string> */
            public array $probed = [];

            protected function exists(string $binary): bool
            {
                $this->probed[] = $binary;

                return true;
            }
        };

        $active = array_values(array_filter(
            Language::getDefaultLanguages(),
            fn (array $language) => (bool) ($language['is_active'] ?? false)
        ));

        $expected = array_values(array_unique(array_map(
            fn (array $language) => (string) $language['extension'],
            $active
        )));

        $this->assertSame(
            $expected,
            $machine->detect($active),
            'uma linguagem ativa que a sonda nao declara e uma linguagem que o judgehost remoto recusa'
        );

        foreach ($machine->probed as $binary) {
            $this->assertStringNotContainsString(
                '{',
                $binary,
                "a sonda procurou `{$binary}`, que e um molde e nao um programa instalado"
            );
        }
    }
}
