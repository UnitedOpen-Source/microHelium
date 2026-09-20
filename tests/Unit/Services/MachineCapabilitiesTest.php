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
     * Issue #305 -- um host com um SDK de .NET so anuncia UMA das duas
     * entradas de C#.
     *
     * Este e o teste que decidiu a forma da mudanca, e ele usa as entradas
     * DE VERDADE do catalogo de proposito: o que se quer proteger nao e o
     * comportamento de `detect()`, que ja estava certo, e sim a escolha de
     * como as duas entradas de C# sao escritas.
     *
     * Enquanto as duas comecavam em `bash`, a sonda nao tinha o que
     * distinguir -- `bash` existe em toda maquina, entao um judgehost com
     * so o SDK 8 anunciava tambem o .NET 10 e recebia trabalho que nao sabe
     * fazer. A mutacao que este teste pega e literalmente essa: devolver os
     * comandos para `bash ...` faz o PATH montado aqui (que tem `csharp-net8`
     * e nao tem `csharp-net10`) passar a anunciar as duas.
     */
    public function test_um_host_com_um_sdk_de_dotnet_so_anuncia_uma_das_duas_entradas_de_csharp(): void
    {
        $catalogo = collect(Language::getDefaultLanguages())
            ->whereIn('extension', ['cs_dotnet', 'cs_dotnet10'])
            ->map(fn (array $l) => (object) $l)
            ->values()
            ->all();

        $this->assertCount(2, $catalogo, 'o catalogo deixou de ter as duas entradas de C#');

        // Um PATH que contem o invocador do .NET 8 e nao o do .NET 10 --
        // um judgehost que so instalou um dos SDKs. `/bin` e `/usr/bin`
        // entram porque a sonda usa `sh -c 'command -v ...'`, mas
        // `/usr/local/bin` fica de fora: e la que a imagem do juiz poe os
        // dois invocadores, e este caso precisa da maquina que tem um so.
        $falso = sys_get_temp_dir().'/mh-cap-csharp-'.getmypid();
        @mkdir($falso, 0o700, true);
        file_put_contents($falso.'/csharp-net8', "#!/bin/sh\nexit 0\n");
        chmod($falso.'/csharp-net8', 0o755);

        $pathAnterior = getenv('PATH');
        putenv('PATH='.$falso.':/bin:/usr/bin');

        try {
            $detectadas = (new MachineCapabilities)->detect($catalogo);
        } finally {
            putenv('PATH='.$pathAnterior);
            @unlink($falso.'/csharp-net8');
            @rmdir($falso);
        }

        $this->assertSame(
            ['cs_dotnet'],
            $detectadas,
            'a sonda de capacidade nao distingue as duas entradas de C#: o primeiro token '
            .'dos comandos precisa ser o invocador da versao (csharp-net8/csharp-net10), '
            .'senao um host com um SDK so anuncia os dois.'
        );
    }
}
