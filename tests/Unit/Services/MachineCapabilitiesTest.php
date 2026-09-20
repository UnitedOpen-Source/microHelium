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
     *
     * O PATH e INJETADO, e nao posto com `putenv`. A primeira versao usava
     * `putenv` e reprovava dentro da imagem do juiz enquanto passava aqui --
     * ver o comentario do construtor de `MachineCapabilities` para o porque
     * (resumo: `$_ENV` vence o `putenv` quando `variables_order` tem `E`, que
     * e o padrao da imagem `php:8.3-cli-alpine`).
     */
    public function test_um_host_com_um_sdk_de_dotnet_so_anuncia_uma_das_duas_entradas_de_csharp(): void
    {
        $catalogo = collect(Language::getDefaultLanguages())
            ->whereIn('extension', ['cs_dotnet', 'cs_dotnet10'])
            ->map(fn (array $l) => (object) $l)
            ->values()
            ->all();

        $this->assertCount(2, $catalogo, 'o catalogo deixou de ter as duas entradas de C#');

        // Uma maquina que instalou o SDK 8 e nao o 10: o PATH tem o
        // `csharp-net8` e nao tem o `csharp-net10`. `/bin` e `/usr/bin`
        // entram porque a sonda usa `sh -c 'command -v ...'` e precisa achar
        // o proprio `sh`; `/usr/local/bin` fica DE FORA de proposito, porque
        // e la que a imagem do juiz poe os dois invocadores e este caso
        // precisa da maquina que tem um so.
        $falso = $this->binFalsoCom(['csharp-net8']);

        try {
            $detectadas = (new MachineCapabilities($falso.':/bin:/usr/bin'))->detect($catalogo);
        } finally {
            $this->removeBinFalso($falso);
        }

        $this->assertSame(
            ['cs_dotnet'],
            $detectadas,
            'a sonda de capacidade nao distingue as duas entradas de C#: o primeiro token '
            .'dos comandos precisa ser o invocador da versao (csharp-net8/csharp-net10), '
            .'senao um host com um SDK so anuncia os dois.'
        );
    }

    /**
     * Issue #305 -- o PATH injetado e mesmo o que a sonda consulta.
     *
     * Guarda do proprio mecanismo do teste acima, e nao da regra de negocio.
     * Ela existe porque o jeito anterior de montar a maquina hipotetica
     * (`putenv`) era um botao que NAO fazia nada dentro da imagem do juiz, e
     * o teste que dependia dele mudava de resposta conforme a
     * `variables_order` do PHP. Se alguem transformar `searchPath` num
     * parametro ignorado, o caso de baixo (o do C#) voltaria a medir o PATH
     * da maquina real; este aqui reprova primeiro, e dizendo o porque.
     *
     * As duas metades importam: achar o que esta no PATH injetado prova que
     * ele e lido, e NAO achar o que so existe fora dele prova que ele
     * substitui o ambiente em vez de ser somado a ele.
     */
    public function test_a_sonda_consulta_o_path_injetado_e_nao_o_do_processo(): void
    {
        $falso = $this->binFalsoCom(['mh-marcador-inventado']);

        try {
            $sonda = new MachineCapabilities($falso.':/bin:/usr/bin');

            $dentro = $sonda->detect([
                $this->language('dentro', null, 'mh-marcador-inventado {source}'),
            ]);

            // `sh` existe em /bin, que esta no PATH injetado, mas NAO no
            // diretorio falso -- serve para provar que o PATH injetado e
            // usado inteiro, e nao so o primeiro item.
            $doPath = $sonda->detect([
                $this->language('sh', null, 'sh {source}'),
            ]);

            // Um PATH injetado que so tem o diretorio falso nao acha `sh`.
            $foraDoPath = (new MachineCapabilities($falso))->detect([
                $this->language('sh', null, 'sh {source}'),
            ]);
        } finally {
            $this->removeBinFalso($falso);
        }

        $this->assertSame(['dentro'], $dentro, 'o PATH injetado nao foi consultado pela sonda');
        $this->assertSame(['sh'], $doPath, 'o PATH injetado perdeu os diretorios depois do primeiro');
        $this->assertSame([], $foraDoPath, 'a sonda achou um binario que o PATH injetado nao cobre -- '
            .'o PATH injetado esta sendo somado ao ambiente em vez de substitui-lo');
    }

    /**
     * Um diretorio com executaveis de mentira, para montar maquinas
     * hipoteticas sem depender do que a maquina de verdade tem instalado.
     *
     * @param  list<string>  $binarios
     */
    private function binFalsoCom(array $binarios): string
    {
        $dir = sys_get_temp_dir().'/mh-cap-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0o700, true);

        foreach ($binarios as $nome) {
            file_put_contents($dir.'/'.$nome, "#!/bin/sh\nexit 0\n");
            chmod($dir.'/'.$nome, 0o755);
        }

        return $dir;
    }

    private function removeBinFalso(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }

        @rmdir($dir);
    }
}
