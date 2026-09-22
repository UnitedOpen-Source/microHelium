<?php

namespace App\Support\Judge;

/**
 * Issue #303 -- como se pergunta a versao de cada toolchain.
 *
 * Esta tabela existia, e morava dentro de `tests/E2E/`. La ela respondia
 * uma pergunta so: "o rotulo do catalogo bate com o que a imagem instalou?".
 * Boa pergunta, e a unica que o `tests/` pode responder -- codigo de teste
 * nao e autoloadado em producao, entao o agente de um judgehost nao tinha
 * como usar a receita.
 *
 * E o agente precisa dela. A spec do julgamento distribuido
 * (docs/specs/53-distributed-judging.md) promete que a capacidade declarada
 * por uma maquina inclui a VERSAO da linguagem, e ate aqui nao incluia:
 * `MachineCapabilities::detect()` devolvia so extensoes, e dois hosts de um
 * parque -- um com GCC 13, outro com GCC 15 -- declaravam exatamente a
 * mesma capacidade.
 *
 * ## Por que nao o pino do Dockerfile
 *
 * O caminho barato seria ler `DockerfileToolchain::pinFor()` e chamar
 * aquilo de "a versao". Isso responde a pergunta errada: o pino e o que o
 * NOSSO repositorio manda instalar, e o host que importa e justamente o que
 * nao foi construido a partir dele -- a maquina no rack da instituicao
 * parceira, com a imagem que ELA construiu, possivelmente noutro dia, com
 * outro `apk` resolvido. Ler o Dockerfile descreveria com muita confianca
 * uma maquina que ninguem consultou.
 *
 * Por isso a versao vem de uma sonda: o proprio toolchain se identifica, na
 * maquina que vai julgar. E o mesmo raciocinio que fez a #117 sondar em vez
 * de configurar ("a configured list is a list someone has to remember to
 * update").
 *
 * ## Por que uma tabela, e nao `--version` em tudo
 *
 * Porque `--version` em tudo esta errado em metade dos casos e, pior,
 * errado em silencio: `fpc` quer `-iV`, `erl` quer um `-eval`, o `dotnet`
 * responde o maior SDK instalado em vez do que a submissao vai usar (#305),
 * e as duas entradas de Java chamam um caminho absoluto por versao. Cada
 * linha aqui foi escolhida para responder pelo MESMO programa que a
 * submissao executa.
 */
final class ToolchainVersions
{
    /**
     * Extensao do catalogo => comando que faz aquele toolchain se
     * identificar.
     *
     * Inclui linguagem desligada de proposito: esta e a RECEITA, e apagar a
     * linha de uma linguagem desativada faria a conferencia ter de ser
     * reescrita do zero no dia da reativacao (#339). Quem decide o que roda
     * e o `is_active` do catalogo, nos dois consumidores.
     *
     * @return array<string, string>
     */
    public static function commands(): array
    {
        return [
            'c_gcc13' => 'gcc -dumpversion',
            'c99_gcc' => 'gcc -dumpversion',
            'cpp_gpp13' => 'g++ -dumpversion',
            'cpp14_gpp' => 'g++ -dumpversion',
            'cpp17_gpp' => 'g++ -dumpversion',
            'c_clang17' => 'clang -dumpversion',
            'cpp_clang' => 'clang++ -dumpversion',
            // O caminho absoluto por versao e o mecanismo do "lado a lado"
            // (#307): `javac` no PATH e um so, e seria o mesmo para as duas
            // entradas.
            'java17' => '/usr/lib/jvm/java-17-openjdk/bin/javac -version 2>&1',
            'java21' => '/usr/lib/jvm/java-21-openjdk/bin/javac -version 2>&1',
            'java25' => '/usr/lib/jvm/java-25-openjdk/bin/javac -version 2>&1',
            'py3' => 'python3 -c "import sys;print(\'%d.%d\' % sys.version_info[:2])"',
            'js_node24' => 'node --version',
            // O rotulo de TypeScript promete o NODE, e nao o tsc -- e o tsc
            // e justamente o que a #303 fixou por versao no Dockerfile.
            'ts' => 'node --version',
            'rs' => 'rustc --version',
            'go' => 'go version',
            'rb' => 'ruby -e "print RUBY_VERSION"',
            'php' => 'php -r "echo PHP_VERSION;"',
            'kt' => 'kotlinc -version 2>&1',
            // Issue #305 -- o INVOCADOR, e nao `dotnet --version`.
            //
            // Com os dois SDKs instalados, `dotnet --version` fora de um
            // projeto responde sempre o maior: medido, 10.0.303 numa imagem
            // com 8.0.131 ao lado. `csharp-netN --version` faz a mesma
            // selecao por `global.json` que o compile.sh faz, entao o que se
            // le aqui e o que a submissao vai usar.
            'cs_dotnet' => 'csharp-net8 --version',
            'cs_dotnet10' => 'csharp-net10 --version',
            'pas_fpc' => 'fpc -iV',
            'perl' => 'perl -e "print substr($^V,1)"',
            'lua' => 'lua5.4 -v',
            'awk' => 'gawk --version',
            // O invocador, e nao `java -cp ...`: o mesmo binario que o
            // catalogo chama e o que responde a versao, senao confere-se uma
            // coisa e a submissao roda outra.
            'clj' => 'clojure-run -e "(println (clojure-version))"',
            'r' => 'Rscript --vanilla -e "cat(R.version.string)"',
            // Issue #339 -- as duas linhas da BEAM ficam aqui DE PROPOSITO,
            // mesmo com `erl` e `ex` desligadas no catalogo. Elas sao a
            // receita pronta para o dia da reativacao.
            'ex' => 'elixir -e "IO.puts(System.version())"',
            // O rotulo promete a OTP (que e o que uma equipe escolhe), e nao
            // a versao do erts.
            'erl' => 'erl -noshell -eval "io:format(erlang:system_info(otp_release)), halt()."',
            'f90' => 'gfortran -dumpversion',
            'f77' => 'gfortran -dumpversion',
            'adb' => 'gnatmake --version',
            'lisp_sbcl' => 'sbcl --version',
            'lisp_clisp' => 'clisp --version',
            'scm' => 'guile --version',
            'rkt' => 'racket --version',
            'zig' => 'zig version',
            'nim' => 'nim --version',
            'cr' => 'crystal --version',
            'd_ldc' => 'ldc2 --version',
            'hs' => 'ghc --numeric-version',
            'ml' => 'ocamlopt -version',
            'scala' => 'scalac -version 2>&1',
            'groovy' => 'groovy --version 2>&1',
            'dart' => 'dart --version 2>&1',
            'cob' => 'cobc --version 2>&1',
            'prolog_swi' => 'swipl --version 2>&1',
            // `gplc --version` escreve a versao no stderr e sai com codigo
            // 1; o `2>&1` ja traz o texto, e quem le so le o texto.
            'prolog_gnu' => 'gplc --version 2>&1',
            // Issue #296. O `gpt -v` escreve tres linhas em portugues, e a
            // versao vem na segunda (`Versao  : 1.2.0`).
            //
            // PELO CAMINHO ABSOLUTO DO INVOCADOR, e nao por `gpt` solto: o
            // nome colide. O macOS traz um `/usr/sbin/gpt` que e a
            // ferramenta de tabela de particao GUID, e com o nome solto o
            // `exists()` o encontra, deixa de pular o caso e compara a
            // versao do compilador com o `usage:` de OUTRO programa. O
            // caminho e o que os tres Dockerfiles instalam.
            'gportugol' => '/usr/local/bin/gpt -v 2>&1',
        ];
    }

    public static function commandFor(string $extension): ?string
    {
        return self::commands()[$extension] ?? null;
    }

    /**
     * O numero de versao que um toolchain acabou de imprimir.
     *
     * Regra unica: o PRIMEIRO grupo de digitos separados por ponto que nao
     * comeca no meio de outro numero. E suficiente para tudo que esta na
     * tabela acima porque todo toolchain imprime a propria versao antes de
     * qualquer outro numero -- `v24.18.1`, `go version go1.26.8`,
     * `SWI-Prolog version 10.1.2 for x86_64-linux`, `Welcome to Racket v9.2.`.
     *
     * O `(?<![\d.])` e o que faz `go1.26.8` valer 1.26.8 e nao 26.8, e e
     * deliberadamente mais frouxo que `(?<![\d.])`: este ultimo recusaria o
     * `v9.2` do Racket, porque o `v` e caractere de palavra.
     *
     * Heuristica declarada como heuristica -- e por isso ela e exercida
     * contra os toolchains DE VERDADE em
     * tests/E2E/ToolchainVersionsMatchCatalogTest, dentro da imagem, para
     * cada linguagem ativa. Uma linha da tabela cuja saida ela nao souber
     * ler reprova la, e nao em producao.
     */
    public static function extract(string $output): ?string
    {
        if (preg_match('/(?<![\d.])(\d+(?:\.\d+)*)/', $output, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
