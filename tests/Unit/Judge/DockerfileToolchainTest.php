<?php

namespace Tests\Unit\Judge;

use App\Support\Judge\DockerfileToolchain;
use App\Support\Judge\ToolchainManifest;
use App\Support\Judge\ToolchainRequirement;
use PHPUnit\Framework\TestCase;

/**
 * Issue #306 -- o leitor de Dockerfile, conferido contra o que ele NAO deve
 * enxergar.
 *
 * Um teste de paridade que responde "sim" para tudo fica verde para sempre e
 * nao protege nada. Entao aqui o leitor e medido nos dois sentidos: acha o
 * que esta escrito, e nao acha o que nao esta. Cada caso corresponde a um
 * jeito real de o teste de paridade virar enfeite:
 *
 *   - ler os estagios de build junto com a imagem final (um `apk add gcc`
 *     num builder nao poe gcc na imagem publicada);
 *   - contar um `chmod` como instalacao;
 *   - aceitar um estagio que existe mas que ninguem copia;
 *   - dar por fixado um download que tem versao e nao tem hash.
 */
class DockerfileToolchainTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $directory) {
            foreach (glob($directory.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }

        $this->tmp = [];

        parent::tearDown();
    }

    private function parse(string $dockerfile): DockerfileToolchain
    {
        $directory = sys_get_temp_dir().'/toolchain-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);
        file_put_contents($directory.'/Dockerfile', $dockerfile);

        $this->tmp[] = $directory;

        return DockerfileToolchain::fromFile($directory.'/Dockerfile', $directory);
    }

    public function test_le_a_lista_de_pacotes_com_e_sem_pino_atravessando_comentarios(): void
    {
        $image = $this->parse(<<<'DOCKER'
        FROM php:8.3-cli-alpine
        RUN apk add --no-cache \
            git \
            $PHPIZE_DEPS \
            # um comentario no meio da lista nao interrompe a continuacao
            gcc=15.2.0-r5 \
            ghc=9.10.3-r2
        DOCKER);

        $packages = $image->apkPackages();

        $this->assertSame('15.2.0-r5', $packages['gcc']);
        $this->assertSame('9.10.3-r2', $packages['ghc']);
        $this->assertNull($packages['git'], 'git aparece na lista, e sem pino');
        $this->assertArrayNotHasKey('$PHPIZE_DEPS', $packages);
        $this->assertArrayNotHasKey('--no-cache', $packages);
    }

    public function test_pacote_instalado_so_num_estagio_de_build_nao_conta(): void
    {
        // O caso que tornaria o teste de paridade inutil: todo Dockerfile
        // daqui tem um `apk add gcc` num builder, e se ele contasse, remover
        // o gcc da imagem final passaria despercebido.
        $image = $this->parse(<<<'DOCKER'
        FROM php:8.3-cli-alpine AS builder
        RUN apk add --no-cache gcc=15.2.0-r5 make

        FROM php:8.3-cli-alpine
        RUN apk add --no-cache bash=5.3.9-r1
        DOCKER);

        $this->assertArrayHasKey('bash', $image->apkPackages());
        $this->assertArrayNotHasKey('gcc', $image->apkPackages());
        $this->assertFalse($image->satisfies(ToolchainRequirement::apk('gcc')));
    }

    public function test_a_lista_do_apk_termina_no_separador_de_shell(): void
    {
        // Issue #391. O que vem depois do `&&` e OUTRO comando. Lido como
        // lista, `docker-php-ext-install` e `pdo_pgsql` viravam pacotes, e o
        // `pinos-alpine.yml` -- que simula exatamente esta lista -- reprovava.
        $image = $this->parse(<<<'DOCKER'
        FROM php:8.3-cli-alpine
        RUN apk add --no-cache libpq bash=5.3.9-r1 \
            && docker-php-ext-install -j4 pdo pdo_pgsql \
            && rm -rf /tmp/x
        RUN apk add --no-cache git; echo pronto
        DOCKER);

        $this->assertSame(['libpq' => null, 'bash' => '5.3.9-r1', 'git' => null], $image->apkPackages());
    }

    public function test_o_argumento_de_uma_opcao_nao_e_pacote(): void
    {
        $image = $this->parse(<<<'DOCKER'
        FROM php:8.3-cli-alpine
        RUN apk add --no-cache --virtual .ferramentas make \
            --repository https://dl-cdn.alpinelinux.org/alpine/edge/testing erlang29
        DOCKER);

        $packages = $image->apkPackages();

        $this->assertArrayHasKey('make', $packages);
        $this->assertArrayHasKey('erlang29', $packages);
        $this->assertArrayNotHasKey('.ferramentas', $packages, 'o nome do grupo --virtual nao e pacote');
        $this->assertArrayNotHasKey('https://dl-cdn.alpinelinux.org/alpine/edge/testing', $packages);
    }

    public function test_grupo_virtual_removido_no_mesmo_run_nao_fica_na_imagem(): void
    {
        // Issue #391: e assim que o `postgresql-dev` entra para compilar o
        // `pdo_pgsql` e sai antes de a camada ser gravada. Nao e pacote da
        // imagem final -- e contar com ele esconderia a economia que o
        // conserto existe para garantir.
        $image = $this->parse(<<<'DOCKER'
        FROM php:8.3-cli-alpine
        RUN apk add --no-cache libpq
        RUN apk add --no-cache --virtual .pgsql-build postgresql-dev \
            && docker-php-ext-install pdo_pgsql \
            && apk del .pgsql-build
        DOCKER);

        $this->assertSame(['libpq' => null], $image->apkPackages());
    }

    public function test_grupo_virtual_que_nao_e_removido_continua_na_imagem(): void
    {
        // O contrario tem de valer tambem: `--virtual` sozinho so da nome ao
        // grupo. Sem o `apk del`, os pacotes ficam -- e uma regra que os
        // descartasse pelo simples `--virtual` esconderia o erro de esquecer
        // a remocao, que e o erro que a #391 corrigiu.
        $image = $this->parse(<<<'DOCKER'
        FROM php:8.3-cli-alpine
        RUN apk add --no-cache --virtual .pgsql-build postgresql-dev \
            && docker-php-ext-install pdo_pgsql
        RUN apk del .outro-grupo
        DOCKER);

        $this->assertSame(['postgresql-dev' => null], $image->apkPackages());
    }

    public function test_um_chmod_nao_e_instalacao_mas_um_printf_e(): void
    {
        $image = $this->parse(<<<'DOCKER'
        FROM php:8.3-cli-alpine
        RUN printf '#!/bin/sh\nexec node /opt/scratch-run/index.js "$@"\n' > /usr/local/bin/scratch-run \
            && chmod 755 /usr/local/bin/scratch-run /usr/local/bin/fantasma
        DOCKER);

        $this->assertTrue($image->satisfies(ToolchainRequirement::invoker('scratch-run')));
        $this->assertFalse(
            $image->satisfies(ToolchainRequirement::invoker('fantasma')),
            'um chmod sobre um caminho nao poe programa nenhum na imagem'
        );
    }

    public function test_a_receita_do_invocador_ignora_o_smoke_test_e_nao_o_corpo(): void
    {
        // Dois invocadores com o MESMO nome e corpos diferentes -- o que o
        // #351 deixou entre o Dockerfile.judge e as imagens da aplicacao.
        $console = $this->parse(<<<'DOCKER'
        FROM php:8.3-cli-alpine
        RUN set -eux; \
            printf '#!/bin/sh\nexec java -jar /opt/p/console.jar "$@" -no-wait\n' \
                > /usr/local/bin/portugol-studio; \
            chmod 755 /usr/local/bin/portugol-studio; \
            echo um smoke test qualquer
        DOCKER);

        $nucleo = $this->parse(<<<'DOCKER'
        FROM php:8.3-cli-alpine
        RUN set -eux; \
            printf '#!/bin/sh\nexec java -cp /opt/p ExecutaPortugol "$@"\n' \
                > /usr/local/bin/portugol-studio; \
            chmod 755 /usr/local/bin/portugol-studio; \
            echo outro smoke test, bem diferente, com mais linhas; \
            echo e mais uma
        DOCKER);

        $this->assertNotSame(
            $console->invokerRecipe('portugol-studio'),
            $nucleo->invokerRecipe('portugol-studio'),
            'o corpo do invocador e o que decide o comportamento; dois corpos diferentes nao sao o mesmo invocador'
        );

        $this->assertStringContainsString('ExecutaPortugol', (string) $nucleo->invokerRecipe('portugol-studio'));
        $this->assertStringNotContainsString(
            'smoke test',
            (string) $nucleo->invokerRecipe('portugol-studio'),
            'a verificacao de fumaca ao redor e diferente de proposito entre as imagens e nao entra na comparacao'
        );
        $this->assertNull($console->invokerRecipe('nao-criado-aqui'));
    }

    public function test_estagio_declarado_e_nao_copiado_nao_satisfaz(): void
    {
        $image = $this->parse(<<<'DOCKER'
        FROM node:24-alpine AS scratch-run-builder
        RUN echo build

        FROM php:8.3-cli-alpine
        RUN echo nada
        DOCKER);

        $this->assertFalse(
            $image->satisfies(ToolchainRequirement::stage('scratch-run-builder')),
            'um estagio que ninguem copia e peso de build, nao conteudo de imagem'
        );
    }

    public function test_download_sem_hash_nao_esta_fixado(): void
    {
        $semHash = $this->parse(<<<'DOCKER'
        FROM php:8.3-cli-alpine
        ARG KOTLIN_VERSION=2.4.20
        RUN wget -q "https://exemplo/kotlin-${KOTLIN_VERSION}.zip"
        DOCKER);

        $this->assertFalse($semHash->satisfies(ToolchainRequirement::download('KOTLIN')));

        $comHash = $this->parse(<<<'DOCKER'
        FROM php:8.3-cli-alpine
        ARG KOTLIN_VERSION=2.4.20
        ARG KOTLIN_SHA256=59e9ca74c7904ef2c122b12114937673ccce68de820a663f0ed66ccf8799e0b7
        RUN wget -q "https://exemplo/kotlin-${KOTLIN_VERSION}.zip"
        DOCKER);

        $this->assertTrue($comHash->satisfies(ToolchainRequirement::download('KOTLIN')));
        $this->assertStringContainsString('KOTLIN_SHA256=', (string) $comHash->pinFor(ToolchainRequirement::download('KOTLIN')));
    }

    public function test_o_npm_global_e_lido_com_a_versao_resolvida_do_arg(): void
    {
        $image = $this->parse(<<<'DOCKER'
        FROM php:8.3-cli-alpine
        ARG TYPESCRIPT_VERSION=7.0.2
        RUN npm install -g "typescript@${TYPESCRIPT_VERSION}"
        DOCKER);

        $this->assertSame('7.0.2', $image->pinFor(ToolchainRequirement::npm('typescript')));
        $this->assertFalse($image->satisfies(ToolchainRequirement::npm('eslint')));
    }

    public function test_os_invocadores_do_repositorio_vem_do_diretorio_e_nao_de_uma_lista(): void
    {
        $image = DockerfileToolchain::fromFile(
            dirname(__DIR__, 3).'/Dockerfile.judge',
            dirname(__DIR__, 3)
        );

        // O `COPY docker/judge/bin/ /usr/local/bin/` traz o diretorio
        // inteiro, entao a lista de invocadores e a do disco.
        foreach (glob(dirname(__DIR__, 3).'/docker/judge/bin/*') ?: [] as $file) {
            $this->assertTrue(
                $image->satisfies(ToolchainRequirement::invoker(basename($file))),
                basename($file).' esta em docker/judge/bin mas o leitor nao o viu chegar em /usr/local/bin'
            );
        }

        $this->assertFalse($image->satisfies(ToolchainRequirement::invoker('nao-existe-este-invocador')));
    }

    public function test_o_executavel_de_um_comando_e_o_que_o_roteamento_veria(): void
    {
        // A regra do roteamento por capacidade e esta mesma: desde a #354 o
        // MachineCapabilities delega para ca, e o ultimo caso e o motivo.
        $this->assertSame('gcc', ToolchainManifest::executableOf('gcc -static -O2 -o {output} {source} -lm'));
        $this->assertSame('python3', ToolchainManifest::executableOf('PYTHONPATH={judge_runtime}/python python3 {source}'));
        $this->assertSame('bash', ToolchainManifest::executableOf('bash {judge_runtime}/node/run.sh {memory} {source}'));
        $this->assertSame(
            '/usr/lib/jvm/java-25-openjdk/bin/javac',
            ToolchainManifest::executableOf('/usr/lib/jvm/java-25-openjdk/bin/javac {source}')
        );
        $this->assertNull(
            ToolchainManifest::executableOf('./{executable}'),
            'o binario que a compilacao acabou de produzir nao e um programa a instalar'
        );
    }

    public function test_os_scripts_de_judge_runtime_entram_como_dependencia(): void
    {
        $this->assertSame(
            ['bash', 'csharp/compile.sh'],
            ToolchainManifest::dependenciesOf('bash {judge_runtime}/csharp/compile.sh {source} {output}')
        );
    }
}
