<?php

namespace Tests\E2E;

use App\Models\Language;
use App\Support\Judge\ToolchainVersions;
use LogicException;
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
     * o prefixo de versao que o rotulo promete, e o rotulo COPIADO.
     *
     * Issue #303 -- o COMANDO saiu daqui. Ele mora em
     * {@see ToolchainVersions}, porque o agente de um
     * judgehost tambem precisa dele para declarar a versao junto da
     * capacidade, e codigo de teste nao e autoloadado em producao. Duas
     * copias da mesma receita e como elas vieram a discordar -- foi a licao
     * da #354, e o remedio foi o mesmo: um dono so. Os comentarios que
     * explicam POR QUE cada comando e aquele foram junto com eles.
     *
     * O que fica aqui e o que e mesmo assunto de teste: a PROMESSA do
     * catalogo. Quem garante que as duas metades continuam cobrindo as
     * mesmas extensoes e tests/Unit/Judge/PromessaDeVersaoTest, na suite de
     * todo PR.
     *
     * A tabela e a RECEITA e inclui linguagem desligada; quem escolhe o que
     * roda e `activeVersionedLanguages()`.
     *
     * Uma linguagem ativa que NAO aparece aqui e coberta pelo teste de
     * cobertura no fim da classe, que falha se alguem prometer uma versao
     * nova sem dizer como conferi-la.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function promessas(): array
    {
        return [
            // extensao => [versao prometida pelo rotulo, rotulo copiado]
            'c_gcc13' => ['15', 'C (GCC 15)'],
            'c99_gcc' => ['15', 'C99 (GCC 15)'],
            'cpp_gpp13' => ['15', 'C++ (G++ 15)'],
            'cpp14_gpp' => ['15', 'C++14 (G++ 15)'],
            'cpp17_gpp' => ['15', 'C++17 (G++ 15)'],
            'c_clang17' => ['22', 'C (Clang 22)'],
            'cpp_clang' => ['22', 'C++ (Clang 22)'],
            'java17' => ['17', 'Java (OpenJDK 17 LTS)'],
            'java21' => ['21', 'Java (OpenJDK 21 LTS)'],
            'java25' => ['25', 'Java (OpenJDK 25 LTS)'],
            'py3' => ['3.14', 'Python 3.14'],
            'js_node24' => ['24', 'JavaScript (Node 24 LTS)'],
            'ts' => ['24', 'TypeScript (Node 24)'],
            'rs' => ['1.96', 'Rust (1.96)'],
            'go' => ['1.26', 'Go (1.26)'],
            'rb' => ['3.4', 'Ruby 3.4'],
            'php' => ['8.3', 'PHP 8.3'],
            'kt' => ['2.4', 'Kotlin (2.4)'],
            'cs_dotnet' => ['8.0', 'C# (.NET 8 LTS)'],
            'cs_dotnet10' => ['10.0', 'C# (.NET 10 LTS)'],
            'pas_fpc' => ['3.2.2', 'Pascal (FPC)'],
            'perl' => ['5', 'Perl 5'],
            'lua' => ['5.4', 'Lua 5.4'],
            'awk' => ['5.3', 'AWK (GAWK 5.3)'],
            'clj' => ['1.12', 'Clojure 1.12'],
            'r' => ['4.6', 'R 4.6'],
            'ex' => ['1.19', 'Elixir 1.19'],
            'erl' => ['27', 'Erlang/OTP 27'],
            'f90' => ['15', 'Fortran (GFortran 15)'],
            'f77' => ['15', 'Fortran 77 (GFortran 15)'],
            'adb' => ['15', 'Ada (GNAT 15)'],
            'lisp_sbcl' => ['2.6', 'Common Lisp (SBCL 2.6)'],
            'lisp_clisp' => ['2.49', 'Common Lisp (CLISP 2.49)'],
            'scm' => ['3.0', 'Scheme (Guile 3.0)'],
            'rkt' => ['9.2', 'Racket 9.2'],
            'zig' => ['0.16', 'Zig 0.16'],
            'nim' => ['2.2', 'Nim 2.2'],
            'cr' => ['1.20', 'Crystal 1.20'],
            'd_ldc' => ['1.42', 'D (LDC 1.42)'],
            'hs' => ['9.10', 'Haskell (GHC 9.10)'],
            'ml' => ['4.14', 'OCaml 4.14'],
            'scala' => ['3.3', 'Scala 3 (3.3 LTS)'],
            'groovy' => ['4.0', 'Groovy 4'],
            'dart' => ['3.13', 'Dart 3.13'],
            'cob' => ['3.2', 'COBOL (GnuCOBOL 3.2)'],
            'prolog_swi' => ['10', 'Prolog (SWI-Prolog 10)'],
            'prolog_gnu' => ['1.5', 'Prolog (GNU Prolog 1.5)'],
            'gportugol' => ['1.2', 'G-Portugol (1.2)'],
        ];
    }

    /**
     * A promessa do catalogo somada ao comando que a confere.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function versionedLanguages(): array
    {
        $casos = [];

        foreach (self::promessas() as $extensao => [$prometida, $rotulo]) {
            $comando = ToolchainVersions::commandFor($extensao);

            if ($comando === null) {
                // Nao ha caso possivel sem comando, e descartar em silencio
                // seria conferencia que evapora. O teste de simetria da
                // suite rapida reprova antes disto, dizendo o porque.
                throw new LogicException(
                    "ToolchainVersions nao sabe perguntar a versao de '{$extensao}'"
                );
            }

            $casos[$extensao] = [$extensao, $comando, $prometida, $rotulo];
        }

        return $casos;
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

        // Issue #303 -- e o extrator de PRODUCAO tem de saber ler esta saida.
        //
        // `ToolchainVersions::extract()` e uma heuristica declarada ("o
        // primeiro numero de versao do texto"), e o judgehost a usa para
        // declarar a versao junto da capacidade. Exerce-la aqui e de graca:
        // a saida de verdade de cada toolchain ativo ja esta na mao, dentro
        // da imagem. Uma linha da receita cuja saida ela nao souber ler
        // reprova neste job, em vez de o judgehost declarar `null` em
        // silencio numa maratona.
        $extraida = ToolchainVersions::extract($reported);

        $this->assertNotNull(
            $extraida,
            "{$extension}: ToolchainVersions::extract() nao achou versao nenhuma em:\n  {$reported}"
        );

        $this->assertMatchesVersion(
            $promised,
            (string) $extraida,
            "{$extension}: o toolchain respondeu '{$reported}', o rotulo promete {$promised}, "
            ."e ToolchainVersions::extract() leu '{$extraida}' -- e essa a versao que o judgehost declararia."
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
