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
     * Para cada entrada `is_active` cujo nome promete um numero de versao:
     * o comando que o proprio toolchain usa para se identificar, e o prefixo
     * de versao que o rotulo promete.
     *
     * Uma linguagem ativa que NAO aparece aqui e coberta pelo teste de
     * cobertura no fim da classe, que falha se alguem prometer uma versao
     * nova sem dizer como conferi-la.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
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
            'cs_dotnet' => ['cs_dotnet', 'dotnet --version', '8.0', 'C# (.NET 8)'],
            'pas_fpc' => ['pas_fpc', 'fpc -iV', '3.2.2', 'Pascal (FPC)'],
            'perl' => ['perl', 'perl -e "print substr($^V,1)"', '5', 'Perl 5'],

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

    #[DataProvider('versionedLanguages')]
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
