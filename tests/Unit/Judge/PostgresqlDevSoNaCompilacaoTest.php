<?php

namespace Tests\Unit\Judge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #391 -- o `postgresql-dev` e dependencia de COMPILACAO, e nao pode
 * sobrar na imagem.
 *
 * ## O que acontecia
 *
 * As tres imagens instalavam `postgresql-dev` na lista grande de `apk add`,
 * para compilar o `pdo_pgsql`. O PostgreSQL 18 traz suporte a JIT, e o
 * `-dev` dele arrasta o Clang e o LLVM 22 inteiros. Medido sobre a base
 * `php:8.3-cli-alpine` (Alpine 3.24.2):
 *
 *     perfil sem clang:  +446 MiB, e sobra um `clang` no PATH de uma imagem
 *                        que nao oferece C/C++ Clang
 *     perfil completo:   +446 MiB, dos quais 140 MiB (o `llvm22`) nao servem
 *                        a linguagem nenhuma
 *
 * O juiz co-localizado (servico `autojudge` do docker-compose) busca as
 * runs direto no banco, entao o DRIVER continua necessario -- quem roda em
 * PostgreSQL precisa do `pdo_pgsql`. O que nao precisa ficar e o cabecalho.
 *
 * ## O que este teste guarda
 *
 * 1. `postgresql-dev` so entra como `--virtual`, e o MESMO `RUN` o remove.
 *    Remover num `RUN` seguinte nao economizaria nada: a camada que o
 *    instalou continuaria na imagem, com o LLVM dentro;
 * 2. o `libpq` de runtime continua instalado, senao o `pdo_pgsql` compila e
 *    nao carrega;
 * 3. o `pdo_pgsql` continua sendo compilado -- o conserto nao pode virar
 *    "tirar o driver", que quebraria quem usa PostgreSQL.
 *
 * Le o Dockerfile como texto, na granularidade de INSTRUCAO: as linhas de
 * continuacao sao juntadas e os comentarios descartados, que e o que o
 * proprio Docker faz antes de executar.
 */
class PostgresqlDevSoNaCompilacaoTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function imagens(): array
    {
        return [
            'Dockerfile' => ['Dockerfile'],
            'Dockerfile.dev' => ['Dockerfile.dev'],
            'Dockerfile.judge' => ['Dockerfile.judge'],
        ];
    }

    #[DataProvider('imagens')]
    public function test_postgresql_dev_so_entra_como_virtual_e_sai_no_mesmo_run(string $arquivo): void
    {
        $runsComDev = array_values(array_filter(
            self::runs($arquivo),
            fn (string $run) => preg_match('/(?<![\w-])postgresql-dev(?![\w-])/', $run) === 1,
        ));

        $this->assertNotSame([], $runsComDev, "{$arquivo}: nenhum RUN instala `postgresql-dev` -- entao quem fornece o `libpq-fe.h` para compilar o `pdo_pgsql`?");

        foreach ($runsComDev as $run) {
            $this->assertMatchesRegularExpression(
                '/apk add\b[^&]*--virtual\s+(\S+)[^&]*\bpostgresql-dev\b/',
                $run,
                "{$arquivo}: `postgresql-dev` instalado fora de um `--virtual`. Ele fica na imagem e leva o Clang/LLVM 22 junto (#391).\nRUN: ".self::resumo($run)
            );

            preg_match('/--virtual\s+(\S+)/', $run, $m);
            $grupo = preg_quote($m[1], '/');

            $this->assertMatchesRegularExpression(
                "/apk del\\s+(?:-\\S+\\s+)*{$grupo}(?!\\S)/",
                $run,
                "{$arquivo}: o grupo `{$m[1]}` e instalado mas nao e removido no MESMO RUN. Remover depois nao economiza nada -- a camada que instalou continua na imagem (#391).\nRUN: ".self::resumo($run)
            );
        }
    }

    #[DataProvider('imagens')]
    public function test_o_libpq_de_runtime_continua_instalado(string $arquivo): void
    {
        $instalado = false;

        foreach (self::runs($arquivo) as $run) {
            if (preg_match('/apk(?:-add| add)\b/', $run) === 1
                && preg_match('/--virtual/', $run) !== 1
                && preg_match('/(?<![\w-])libpq(?![\w-])/', $run) === 1) {
                $instalado = true;
            }
        }

        $this->assertTrue(
            $instalado,
            "{$arquivo}: nenhum `apk add` persistente instala o `libpq`. O `pdo_pgsql` compila (o `-dev` estava la na hora) e depois nao carrega, porque o `libpq.so.5` saiu junto com o grupo virtual."
        );
    }

    #[DataProvider('imagens')]
    public function test_o_driver_do_postgresql_continua_sendo_compilado(string $arquivo): void
    {
        $compila = false;

        foreach (self::runs($arquivo) as $run) {
            if (preg_match('/docker-php-ext-install\b.*\bpdo_pgsql\b/', $run) === 1) {
                $compila = true;
            }
        }

        $this->assertTrue(
            $compila,
            "{$arquivo}: o `pdo_pgsql` deixou de ser compilado. O juiz co-localizado busca as runs no banco, e quem roda em PostgreSQL ficaria sem driver -- o conserto da #391 e tirar o CABECALHO, nao o driver."
        );
    }

    /**
     * As instrucoes RUN do arquivo, uma por string, com as continuacoes
     * juntadas e as linhas de comentario descartadas.
     *
     * @return list<string>
     */
    private static function runs(string $arquivo): array
    {
        $fonte = file_get_contents(__DIR__.'/../../../'.$arquivo);
        self::assertIsString($fonte, "{$arquivo} nao foi lido");

        $instrucoes = [];
        $atual = null;

        foreach (explode("\n", $fonte) as $linha) {
            if (preg_match('/^\s*#/', $linha) === 1) {
                continue;
            }

            if ($atual === null) {
                if (preg_match('/^RUN\s/', $linha) !== 1) {
                    continue;
                }
                $atual = '';
            }

            $continua = preg_match('/\\\\\s*$/', $linha) === 1;
            $atual .= ' '.trim(preg_replace('/\\\\\s*$/', '', $linha));

            if (! $continua) {
                $instrucoes[] = preg_replace('/\s+/', ' ', trim($atual));
                $atual = null;
            }
        }

        return $instrucoes;
    }

    private static function resumo(string $run): string
    {
        return strlen($run) > 240 ? substr($run, 0, 240).' ...' : $run;
    }
}
