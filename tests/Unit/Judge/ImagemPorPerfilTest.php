<?php

namespace Tests\Unit\Judge;

use App\Support\Judge\DockerfileToolchain;
use App\Support\Judge\ToolchainManifest;
use App\Support\Judge\ToolchainProfile;
use App\Support\Judge\ToolchainRequirement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Issue #306, passo 3 -- o `Dockerfile.judge` constroi a imagem de um perfil.
 *
 * O build por perfil tem tres pecas que precisam concordar, e cada uma pode
 * divergir das outras em silencio:
 *
 *   1. o que o `ToolchainProfile` diz que o perfil NAO usa (`cuts()`);
 *   2. o arquivo `docker/judge/perfil/<perfil>.corta` que o build le;
 *   3. as guardas `perfil inclui ...` espalhadas no `Dockerfile.judge`, e o
 *      script POSIX que as responde.
 *
 * Construir a imagem para descobrir que duas delas discordam custa dezenas de
 * minutos e so acontece no CI. Estas asserções custam milissegundos e rodam na
 * suite de sempre -- o mesmo criterio do #353. O que so a imagem responde (a
 * linguagem prometida sobe de verdade la dentro) esta em
 * tests/E2E/PerfilDaImagemTest.php.
 */
class ImagemPorPerfilTest extends TestCase
{
    private static function raiz(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function dir(): string
    {
        return self::raiz().'/docker/judge/perfil';
    }

    /** @return list<string> */
    private static function versionado(string $perfil): array
    {
        $texto = (string) @file_get_contents(self::dir()."/{$perfil}.corta");

        return array_values(array_filter(array_map('trim', explode("\n", $texto)), 'strlen'));
    }

    public function test_cada_perfil_tem_o_seu_corta_e_so_eles_tem(): void
    {
        $esperados = array_map(fn (string $p): string => "{$p}.corta", array_keys(ToolchainProfile::all()));
        $existentes = array_map('basename', glob(self::dir().'/*.corta') ?: []);

        sort($esperados);
        sort($existentes);

        $this->assertSame(
            $esperados,
            $existentes,
            "docker/judge/perfil/ tem de ter exatamente um .corta por perfil de ToolchainProfile::all().\n"
            .'Perfil sem arquivo reprova o build; arquivo sem perfil e um nome que o build aceita e o codigo nao conhece.'
        );
    }

    public function test_o_corta_versionado_e_o_que_o_perfil_resolve(): void
    {
        $divergencias = [];

        foreach (ToolchainProfile::all() as $nome => $perfil) {
            if (self::versionado($nome) !== $perfil->cuts()) {
                $divergencias[] = $nome;
            }
        }

        $this->assertSame(
            [],
            $divergencias,
            'os .corta destes perfis nao descrevem mais o que o ToolchainProfile resolve: '.implode(', ', $divergencias)."\n"
            ."Regere com:\n"
            ."    for p in maratona scripting funcional completo; do\n"
            ."      php artisan judge:toolchain-profile \$p --corta > docker/judge/perfil/\$p.corta\n"
            .'    done'
        );
    }

    /**
     * O `completo` e o padrao do build, e o padrao tem de ser a imagem de
     * sempre: nenhum pacote, download ou pacote npm a menos.
     */
    public function test_o_completo_nao_corta_nada(): void
    {
        $this->assertSame([], self::versionado('completo'));
    }

    /**
     * O defeito que motivou `bash` em ToolchainManifest::shared(): cortar
     * infraestrutura nao deixa a imagem menor, deixa ela inutil.
     */
    public function test_nenhum_perfil_corta_infraestrutura(): void
    {
        $infra = array_map(fn (ToolchainRequirement $r): string => $r->key(), ToolchainManifest::shared());

        foreach (array_keys(ToolchainProfile::all()) as $nome) {
            $this->assertSame(
                [],
                array_values(array_intersect(self::versionado($nome), $infra)),
                "o perfil {$nome} corta infraestrutura do juiz"
            );
        }

        $this->assertContains('apk:bash', $infra, 'o `bash -c` do AutoJudgeService embrulha todo comando julgado');
    }

    /**
     * As guardas do Dockerfile e os cortes possiveis, nos dois sentidos.
     *
     * Guarda com alvo inexistente (`download:KOTLN`) nunca e cortada: a imagem
     * enxuta sai maior do que o perfil promete, sem erro. Corte sem guarda (um
     * download novo que alguem acrescentou sem `perfil inclui`) e o mesmo
     * defeito pelo outro lado.
     */
    public function test_as_guardas_do_dockerfile_e_os_cortes_possiveis_casam(): void
    {
        $dockerfile = (string) file_get_contents(self::raiz().'/Dockerfile.judge');
        preg_match_all('#/perfil\s+inclui\s+(\S+?)(?:;|\s)#', $dockerfile, $m);
        $guardas = array_values(array_unique($m[1]));

        $completo = [];
        foreach ((ToolchainProfile::named('completo') ?? throw new \RuntimeException('sem completo'))->requirements() as $r) {
            $completo[$r->key()] = $r->kind;
        }

        foreach ($guardas as $guarda) {
            $this->assertArrayHasKey($guarda, $completo, "Dockerfile.judge guarda `{$guarda}`, que nao e exigencia de nenhuma linguagem ativa");
        }

        foreach ($completo as $chave => $kind) {
            if (in_array($kind, [ToolchainRequirement::DOWNLOAD, ToolchainRequirement::NPM], true)) {
                $this->assertContains($chave, $guardas, "`{$chave}` pode ser cortado por um perfil, e o Dockerfile.judge o instala sem perguntar");
            }
        }

        $this->assertMatchesRegularExpression(
            '#^RUN\s+\S*/perfil\s+apk-add\b#m',
            $dockerfile,
            'a lista de apk do Dockerfile.judge tem de passar pelo `perfil apk-add`'
        );
    }

    /**
     * O script, rodado de verdade: a lista que ele entrega ao `apk` e a lista
     * do Dockerfile menos o que o perfil corta -- nem mais, nem menos.
     */
    public function test_o_script_perfil_filtra_a_lista_do_dockerfile_como_o_gerador_manda(): void
    {
        $imagem = DockerfileToolchain::fromFile(self::raiz().'/Dockerfile.judge', self::raiz());
        $argumentos = ['--no-cache'];

        foreach ($imagem->apkPackages() as $nome => $pino) {
            $argumentos[] = $pino === null ? $nome : "{$nome}={$pino}";
        }

        foreach (array_keys(ToolchainProfile::all()) as $nome) {
            $processo = new Process(['sh', self::dir().'/perfil', 'filtra', ...$argumentos], null, ['JUDGE_PROFILE' => $nome]);
            $processo->mustRun();

            $saida = preg_split('/\s+/', trim($processo->getOutput()), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $cortados = array_map(fn (string $c): string => substr($c, 4), array_filter(self::versionado($nome), fn (string $c): bool => str_starts_with($c, 'apk:')));

            $esperado = array_values(array_filter($argumentos, fn (string $a): bool => ! in_array(explode('=', $a, 2)[0], $cortados, true)));

            $this->assertSame($esperado, $saida, "o `perfil filtra` do perfil {$nome} nao entrega ao apk o que o gerador manda");
        }
    }

    public function test_perfil_desconhecido_reprova_o_build(): void
    {
        $processo = new Process(['sh', self::dir().'/perfil', 'inclui', 'download:KOTLIN'], null, ['JUDGE_PROFILE' => 'maratonaa']);
        $processo->run();

        $this->assertNotSame(0, $processo->getExitCode(), 'um perfil com erro de digitacao nao pode virar `completo` em silencio');
        $this->assertStringContainsString('maratonaa', $processo->getErrorOutput());
    }
}
