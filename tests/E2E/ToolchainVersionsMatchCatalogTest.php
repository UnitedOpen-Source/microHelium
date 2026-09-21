<?php

namespace Tests\E2E;

use App\Models\Language;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #303 -- o rotulo tem de ser verdade.
 *
 * O `name` de cada linguagem e o texto que a equipe le no `<select>` de
 * envio, no historico da submissao, na tela do juiz e no feed CLICS. Numa
 * maratona a versao do compilador e parte do edital: a equipe treina contra
 * uma versao e espera julgar contra ela.
 *
 * Ate esta mudanca nada comparava as duas coisas, e sete rotulos estavam
 * errados -- `C (GCC 13)` com gcc 15.2.0 instalado, `Rust (1.75)` com
 * rustc 1.96.1, `Python 3.12` com CPython 3.14.7. Nenhum teste falhava,
 * porque `MultiLanguageJudgingTest` so exige que a linguagem compile um
 * `a+b` e receba AC -- o que passaria igual com gcc 9 ou gcc 20.
 *
 * Este teste e a peca que faltava, e e deliberadamente o gemeo daquele:
 * um pergunta "funciona?", o outro pergunta "e a versao que prometemos?".
 *
 * Ele so pode rodar onde os toolchains existem, ou seja dentro da imagem do
 * juiz -- que e exatamente onde o CI o roda, com `--fail-on-skipped`. Numa
 * maquina sem o toolchain o caso se pula; no job `judge-image` um `skip` e
 * falha, entao a cobertura nao pode evaporar em silencio.
 */
class ToolchainVersionsMatchCatalogTest extends TestCase
{
    /**
     * Para cada entrada do catalogo cujo nome promete um numero de versao:
     * o comando que o proprio toolchain usa para se identificar, e o prefixo
     * de versao que o rotulo promete.
     *
     * Esta tabela e a RECEITA e inclui linguagem desligada; quem escolhe o
     * que roda e `activeVersionedLanguages()`.
     *
     * Uma linguagem ativa que NAO aparece aqui e coberta pelo teste de
     * cobertura no fim da classe, que falha se alguem prometer uma versao
     * nova sem dizer como conferi-la.
     *
     * O quarto campo e o rotulo do catalogo COPIADO. Copia nao conferida
     * envelhece, e envelhecida ela deixa este teste verde conferindo a
     * versao que o catalogo ja nao promete -- por isso
     * tests/Unit/Judge/PromessaDeVersaoTest.php exige, na suite de todo PR,
     * que ele continue sendo o `name` da entrada e que o `promised` seja
     * coerente com o numero desse rotulo.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function versionedLanguages(): array
    {
        return [
            // extensao => [comando, versao prometida pelo rotulo, rotulo]
            'c_gcc13' => ['c_gcc13', 'gcc -dumpversion', '15', 'C (GCC 15)'],
            'c99_gcc' => ['c99_gcc', 'gcc -dumpversion', '15', 'C99 (GCC 15)'],
            'cpp_gpp13' => ['cpp_gpp13', 'g++ -dumpversion', '15', 'C++ (G++ 15)'],
            'cpp14_gpp' => ['cpp14_gpp', 'g++ -dumpversion', '15', 'C++14 (G++ 15)'],
            'cpp17_gpp' => ['cpp17_gpp', 'g++ -dumpversion', '15', 'C++17 (G++ 15)'],
            'c_clang17' => ['c_clang17', 'clang -dumpversion', '22', 'C (Clang 22)'],
            'cpp_clang' => ['cpp_clang', 'clang++ -dumpversion', '22', 'C++ (Clang 22)'],
            'java21' => ['java21', '/usr/lib/jvm/java-21-openjdk/bin/javac -version 2>&1', '21', 'Java (OpenJDK 21 LTS)'],
            'java25' => ['java25', '/usr/lib/jvm/java-25-openjdk/bin/javac -version 2>&1', '25', 'Java (OpenJDK 25 LTS)'],
            'py3' => ['py3', 'python3 -c "import sys;print(\'%d.%d\' % sys.version_info[:2])"', '3.14', 'Python 3.14'],
            'js_node24' => ['js_node24', 'node --version', '24', 'JavaScript (Node 24 LTS)'],
            // O rotulo de TypeScript promete o NODE, e nao o tsc -- e o tsc
            // e justamente o que a #303 fixou por versao no Dockerfile.
            'ts' => ['ts', 'node --version', '24', 'TypeScript (Node 24)'],
            'rs' => ['rs', 'rustc --version', '1.96', 'Rust (1.96)'],
            'go' => ['go', 'go version', '1.26', 'Go (1.26)'],
            'rb' => ['rb', 'ruby -e "print RUBY_VERSION"', '3.4', 'Ruby 3.4'],
            'php' => ['php', 'php -r "echo PHP_VERSION;"', '8.3', 'PHP 8.3'],
            'kt' => ['kt', 'kotlinc -version 2>&1', '2.4', 'Kotlin (2.4)'],
            // Issue #305 -- o INVOCADOR, e nao `dotnet --version`.
            //
            // Com os dois SDKs instalados, `dotnet --version` fora de um
            // projeto responde sempre o maior: medido, 10.0.303 numa imagem
            // com 8.0.131 ao lado. O comando antigo passaria a reprovar a
            // entrada do .NET 8 dizendo que o rotulo mente -- quando quem
            // mentia era a pergunta.
            //
            // `csharp-netN --version` faz a mesma selecao por `global.json`
            // que o compile.sh faz, entao o que este teste confere e o que a
            // submissao vai usar.
            'cs_dotnet' => ['cs_dotnet', 'csharp-net8 --version', '8.0', 'C# (.NET 8 LTS)'],
            'cs_dotnet10' => ['cs_dotnet10', 'csharp-net10 --version', '10.0', 'C# (.NET 10 LTS)'],
            'pas_fpc' => ['pas_fpc', 'fpc -iV', '3.2.2', 'Pascal (FPC)'],
            'perl' => ['perl', 'perl -e "print substr($^V,1)"', '5', 'Perl 5'],

            // Issue #305, Lote C. Cada comando aqui foi rodado dentro da
            // imagem antes de entrar, e o `promised` e o prefixo que o
            // rotulo do catalogo anuncia.
            'lua' => ['lua', 'lua5.4 -v', '5.4', 'Lua 5.4'],
            'awk' => ['awk', 'gawk --version', '5.3', 'AWK (GAWK 5.3)'],
            // O invocador, e nao `java -cp ...`: o mesmo binario que o
            // catalogo chama e o que responde a versao, senao o teste
            // confere uma coisa e a submissao roda outra.
            'clj' => ['clj', 'clojure-run -e "(println (clojure-version))"', '1.12', 'Clojure 1.12'],
            'r' => ['r', 'Rscript --vanilla -e "cat(R.version.string)"', '4.6', 'R 4.6'],
            // Issue #339 -- as duas linhas da BEAM ficam aqui DE PROPOSITO,
            // mesmo com `erl` e `ex` desligadas no catalogo. Elas sao a
            // receita pronta para o dia da reativacao; quem decide se o caso
            // roda e `activeVersionedLanguages()`, logo abaixo. Apagar as
            // duas faria a conferencia de versao ter de ser reescrita do zero
            // quando o Alpine publicar um erlang com o erlang/otp#11376.
            'ex' => ['ex', 'elixir -e "IO.puts(System.version())"', '1.19', 'Elixir 1.19'],
            // O rotulo promete a OTP (que e o que uma equipe escolhe), e
            // nao a versao do erts.
            'erl' => ['erl', 'erl -noshell -eval "io:format(erlang:system_info(otp_release)), halt()."', '27', 'Erlang/OTP 27'],
            // As duas entradas de Fortran sao o mesmo gfortran; o numero
            // do rotulo e o do COMPILADOR, e o "77" ao lado e o padrao da
            // linguagem.
            'f90' => ['f90', 'gfortran -dumpversion', '15', 'Fortran (GFortran 15)'],
            'f77' => ['f77', 'gfortran -dumpversion', '15', 'Fortran 77 (GFortran 15)'],
            'adb' => ['adb', 'gnatmake --version', '15', 'Ada (GNAT 15)'],
            'lisp_sbcl' => ['lisp_sbcl', 'sbcl --version', '2.6', 'Common Lisp (SBCL 2.6)'],
            'lisp_clisp' => ['lisp_clisp', 'clisp --version', '2.49', 'Common Lisp (CLISP 2.49)'],
            'scm' => ['scm', 'guile --version', '3.0', 'Scheme (Guile 3.0)'],
            'rkt' => ['rkt', 'racket --version', '9.2', 'Racket 9.2'],
            'zig' => ['zig', 'zig version', '0.16', 'Zig 0.16'],
            'nim' => ['nim', 'nim --version', '2.2', 'Nim 2.2'],
            'cr' => ['cr', 'crystal --version', '1.20', 'Crystal 1.20'],
            'd_ldc' => ['d_ldc', 'ldc2 --version', '1.42', 'D (LDC 1.42)'],
            'hs' => ['hs', 'ghc --numeric-version', '9.10', 'Haskell (GHC 9.10)'],
            'ml' => ['ml', 'ocamlopt -version', '4.14', 'OCaml 4.14'],
            // Issue #305, Lote D -- as seis que vieram de fora do Alpine.
            //
            // Aqui o rotulo nao anuncia so "a versao que o distro calhou de
            // ter": cada uma destas e um artefato ESCOLHIDO e fixado por
            // sha256 no Dockerfile. Se alguem subir o pino e esquecer o
            // rotulo (ou o contrario), este teste e quem avisa.
            'scala' => ['scala', 'scalac -version 2>&1', '3.3', 'Scala 3 (3.3 LTS)'],
            'groovy' => ['groovy', 'groovy --version 2>&1', '4.0', 'Groovy 4'],
            'dart' => ['dart', 'dart --version 2>&1', '3.13', 'Dart 3.13'],
            'cob' => ['cob', 'cobc --version 2>&1', '3.2', 'COBOL (GnuCOBOL 3.2)'],
            'prolog_swi' => ['prolog_swi', 'swipl --version 2>&1', '10', 'Prolog (SWI-Prolog 10)'],
            // `gplc --version` escreve a versao no stderr e sai com codigo
            // 1; o `2>&1` do comando ja traz o texto, e o teste so le o
            // texto.
            'prolog_gnu' => ['prolog_gnu', 'gplc --version 2>&1', '1.5', 'Prolog (GNU Prolog 1.5)'],
        ];
    }

    /**
     * Issue #339 -- a tabela acima e a RECEITA; quem manda e o catalogo.
     *
     * A desativacao de `erl` e `ex` (PR #346) parou no `is_active` e nao
     * chegou aqui. O efeito foi medido, e nao suposto: as tres imagens
     * continuam instalando `erlang27` e `elixir`, entao o `exists()` do caso
     * responde "sim" dentro da imagem do juiz e o teste roda
     * `erl -noshell -eval ...` -- exatamente o comando cuja falha
     * (`sys_sigaltstack(): Failed to set alternate signal stack`) motivou a
     * desativacao. Num runner afetado, a linguagem que decidimos NAO oferecer
     * pinta de vermelho o job de um PR que nao tem relacao nenhuma com ela.
     * Foi assim que a #339 nasceu: dos quatro testes que ela derrubou no CI
     * do #315, dois eram deste arquivo.
     *
     * O PR #349 tratou o mesmo problema no teste das vinte partidas, que
     * passou a exigir 20/20 so se oferecermos a linguagem. Aqui e a mesma
     * regra pela mesma razao: conferir a versao prometida de uma linguagem
     * que o formulario de envio nao oferece nao mede nada que uma equipe
     * possa usar.
     *
     * Filtrar (e nao pular): `markTestSkipped` seria vermelho igual, porque
     * o job do juiz roda com `--fail-on-skipped` de proposito. Um caso que
     * nao existe nao e um caso que se pula.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function activeVersionedLanguages(): array
    {
        $ativas = [];

        foreach (Language::getDefaultLanguages() as $language) {
            if (($language['is_active'] ?? false) === true) {
                $ativas[(string) $language['extension']] = true;
            }
        }

        return array_filter(
            self::versionedLanguages(),
            fn (array $caso) => isset($ativas[$caso[0]])
        );
    }

    #[DataProvider('activeVersionedLanguages')]
    public function test_the_installed_toolchain_is_the_version_the_catalog_promises(
        string $extension,
        string $command,
        string $promised,
        string $label
    ) {
        $binary = $this->binaryOf($command);

        if (! $this->exists($binary)) {
            $this->markTestSkipped("{$binary} nao esta nesta maquina (rode dentro da imagem do juiz)");
        }

        $reported = trim((string) shell_exec($command.' 2>&1'));

        $this->assertNotSame('', $reported, "{$extension}: `{$command}` nao devolveu nada");

        // O numero prometido tem de aparecer como VERSAO, e nao em qualquer
        // lugar do texto: `go version go1.26.8` contem "1.2" por acidente.
        $this->assertMatchesVersion(
            $promised,
            $reported,
            "o catalogo anuncia '{$label}' para '{$extension}', mas o toolchain instalado responde:\n"
            ."  {$command}\n  => {$reported}\n"
            .'Corrija o rotulo em Language::getDefaultLanguages() ou o pin no Dockerfile -- os dois nao podem discordar.'
        );
    }

    /**
     * Toda linguagem ativa cujo nome carrega um numero tem de estar na
     * tabela acima.
     *
     * Sem isto, acrescentar `Java (OpenJDK 29)` ao catalogo passaria sem
     * ninguem conferir nada -- o provedor de dados simplesmente nao teria um
     * caso para ela, e a suite ficaria verde por omissao.
     */
    public function test_every_active_language_that_promises_a_version_is_checked_here()
    {
        $coberto = array_keys(self::versionedLanguages());

        $prometem = collect(Language::getDefaultLanguages())
            ->where('is_active', true)
            ->filter(fn (array $l) => preg_match('/\d/', $l['name']) === 1)
            ->pluck('extension')
            ->reject(fn (string $ext) => in_array($ext, $coberto, true))
            ->values()
            ->all();

        $this->assertEmpty(
            $prometem,
            'linguagens ativas que anunciam versao no nome e ninguem confere: '.implode(', ', $prometem)
        );
    }

    /**
     * O filtro de `activeVersionedLanguages()` nao pode virar um sumidouro.
     *
     * Ele descarta silenciosamente todo caso cuja extensao nao esteja ativa
     * -- inclusive uma extensao que nao existe mais, ou que alguem digitou
     * errado. Sem este caso, renomear `py3` no catalogo faria a conferencia
     * de versao do Python evaporar sem ninguem ver: o provedor pararia de
     * gerar o caso e o teste de cobertura logo acima reclamaria de `py3`
     * ausente da tabela apontando para uma linha que ESTA la, escrita com o
     * nome velho.
     */
    public function test_a_tabela_so_nomeia_extensoes_que_existem_no_catalogo()
    {
        $catalogo = collect(Language::getDefaultLanguages())->pluck('extension')->all();

        $fantasmas = array_values(array_diff(array_keys(self::versionedLanguages()), $catalogo));

        $aviso = <<<'TXT'

            Uma linha orfa aqui e conferencia que nunca roda: o provedor a descarta em silencio.
            TXT;

        $this->assertEmpty(
            $fantasmas,
            'a tabela confere a versao de extensoes que o catalogo nao tem: '.implode(', ', $fantasmas).$aviso
        );
    }

    private function assertMatchesVersion(string $promised, string $reported, string $message): void
    {
        $quoted = preg_quote($promised, '/');

        // Ancorado em fronteira de versao dos dois lados: "1.26" casa com
        // "go1.26.8" e com "1.26", e nao com "1.260" nem com "11.26".
        $ok = preg_match('/(?<![\d.])'.$quoted.'(?![\d])/', $reported) === 1
            || preg_match('/(?<![\d.])'.$quoted.'\./', $reported) === 1;

        $this->assertTrue($ok, $message);
    }

    private function binaryOf(string $command): string
    {
        $first = preg_split('/\s+/', trim($command))[0] ?? '';

        return $first;
    }

    private function exists(string $binary): bool
    {
        if ($binary === '') {
            return false;
        }

        if (str_starts_with($binary, '/')) {
            return is_executable($binary);
        }

        return trim((string) shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null')) !== '';
    }
}
