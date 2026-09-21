<?php

namespace Tests\Unit\Judge;

use App\Models\Language;
use App\Support\Judge\ToolchainVersions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\ToolchainVersionsMatchCatalogTest;
use Tests\TestCase;

/**
 * Issue #303 -- a receita de "como se pergunta a versao" e o extrator que le
 * a resposta.
 *
 * Este arquivo e a metade que NAO precisa de toolchain nenhum instalado, e
 * por isso roda na suite de todo PR. A outra metade -- o extrator contra a
 * saida de verdade de 40 e tantos compiladores -- roda dentro da imagem do
 * juiz, em tests/E2E/ToolchainVersionsMatchCatalogTest.
 */
class ToolchainVersionsTest extends TestCase
{
    /**
     * A regra do extrator, caso a caso.
     *
     * As saidas marcadas MEDIDO foram lidas desta maquina (macOS, 21/09/2026)
     * rodando o comando da propria tabela; as outras sao o formato conhecido
     * do toolchain, e quem as confere contra o programa de verdade e o teste
     * de dentro da imagem.
     *
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function saidas(): array
    {
        return [
            // MEDIDO nesta maquina.
            'gcc -dumpversion' => ['21.0.0', '21.0.0'],
            'node --version' => ['v24.13.0', '24.13.0'],
            'go version' => ['go version go1.27.1 darwin/arm64', '1.27.1'],
            'rustc --version' => ['rustc 1.98.1 (48a229cea 2026-09-01) (Homebrew)', '1.98.1'],
            'python3 -c ...' => ['3.14', '3.14'],
            'ruby -e RUBY_VERSION' => ['4.0.7', '4.0.7'],
            'php -r PHP_VERSION' => ['8.5.10', '8.5.10'],

            // Formato conhecido. Cada um destes existe porque quebra uma
            // regra mais simples que a escolhida.
            //
            // O `v` colado: `(?<![\w.])` (a versao "obvia" da regra) recusaria
            // este, porque `v` e caractere de palavra, e o Racket ficaria sem
            // versao nenhuma.
            'racket --version' => ['Welcome to Racket v9.2.', '9.2'],
            // O numero da ARQUITETURA vem depois do da versao, e por isso o
            // "primeiro" e o certo.
            'swipl --version' => ['SWI-Prolog version 10.1.2 for x86_64-linux', '10.1.2'],
            // `javac -version` escreve o proprio nome antes, sem digito.
            'javac -version' => ['javac 21.0.12', '21.0.12'],
            // A JVM do kotlinc imprime a versao dela DEPOIS da do compilador.
            'kotlinc -version' => ['info: kotlinc-jvm 2.4.20 (JRE 21.0.8+9)', '2.4.20'],
            'gawk --version' => ['GNU Awk 5.3.2, API 4.0, PMA Avon 8-g1', '5.3.2'],
            'erl otp_release' => ['27', '27'],
            'clisp --version' => ['GNU CLISP 2.49.93+ (2018-02-18)', '2.49.93'],

            // Sem numero nenhum nao ha versao a declarar, e a resposta e
            // "nao sei" -- nao uma string vazia que pareceria um valor.
            'toolchain mudo' => ['command not found', null],
            'saida vazia' => ['', null],
        ];
    }

    #[DataProvider('saidas')]
    public function test_o_extrator_le_a_versao_que_o_toolchain_imprimiu(string $saida, ?string $esperado): void
    {
        $this->assertSame($esperado, ToolchainVersions::extract($saida));
    }

    /**
     * As duas metades da receita cobrem exatamente as mesmas extensoes.
     *
     * A receita foi partida em duas de proposito (#303): o COMANDO e
     * producao, porque o agente do judgehost precisa dele; a PROMESSA e
     * teste, porque so o teste se importa com o rotulo. Partida, ela pode
     * ficar torta, e torta ela falha do jeito ruim -- uma extensao com
     * promessa e sem comando faz o provedor daquele teste explodir dentro do
     * job mais caro do CI, e uma com comando e sem promessa e um comando que
     * ninguem nunca confere contra nada.
     *
     * Por isso a simetria e conferida aqui, na suite de todo PR, e nao la.
     */
    public function test_comando_e_promessa_cobrem_as_mesmas_extensoes(): void
    {
        $comandos = array_keys(ToolchainVersions::commands());
        $promessas = array_keys(ToolchainVersionsMatchCatalogTest::promessas());

        sort($comandos);
        sort($promessas);

        $this->assertSame(
            $promessas,
            $comandos,
            "A receita de versao esta torta entre as duas metades.\n"
            .'sem comando em App\Support\Judge\ToolchainVersions: '.implode(', ', array_diff($promessas, $comandos))."\n"
            .'sem promessa em tests/E2E/ToolchainVersionsMatchCatalogTest: '.implode(', ', array_diff($comandos, $promessas))
        );
    }

    /**
     * E a receita so nomeia extensoes que o catalogo tem.
     *
     * Mesma razao da linha orfa do outro arquivo: um comando para uma
     * extensao que nao existe nunca roda, e o judgehost nunca o consulta.
     */
    public function test_a_receita_so_nomeia_extensoes_do_catalogo(): void
    {
        $catalogo = collect(Language::getDefaultLanguages())->pluck('extension')->all();

        $fantasmas = array_values(array_diff(array_keys(ToolchainVersions::commands()), $catalogo));

        $this->assertSame(
            [],
            $fantasmas,
            'ToolchainVersions sabe perguntar a versao de extensoes que o catalogo nao tem: '
            .implode(', ', $fantasmas)
        );
    }

    /**
     * Toda linguagem ativa que ANUNCIA um numero no rotulo sabe dizer a
     * propria versao.
     *
     * Sem isto, ligar `Java (OpenJDK 29)` no catalogo faria o judgehost
     * declara-la sem versao para sempre, e ninguem saberia -- capacidade sem
     * versao e um estado legitimo (agente anterior a #303), entao ela nao
     * chama a atencao de ninguem. E a forma de falha que a #303 descreve:
     * parece certo.
     *
     * O recorte e o do rotulo de proposito, e nao "toda linguagem ativa".
     * Cinco ativas ficam de fora hoje -- `scratch` e `portugol_studio`, que
     * sao fixadas por COMMIT e nao por versao, e `sh`, `sed` e `tcl`, cujo
     * rotulo nao promete numero nenhum. Exigi-las aqui obrigaria a inventar
     * uma promessa que ninguem mediu, e promessa nao medida e exatamente o
     * defeito que a #303 abriu.
     */
    public function test_toda_linguagem_que_promete_versao_sabe_dizer_a_propria(): void
    {
        $comandos = ToolchainVersions::commands();

        $mudas = collect(Language::getDefaultLanguages())
            ->where('is_active', true)
            ->filter(fn (array $l) => preg_match('/\d/', (string) $l['name']) === 1)
            ->pluck('extension')
            ->reject(fn (string $extensao) => isset($comandos[$extensao]))
            ->values()
            ->all();

        $this->assertSame(
            [],
            $mudas,
            'Linguagem ativa que anuncia versao no rotulo e que o judgehost nao sabe versionar: '
            .implode(', ', $mudas)
            ."\nAcrescente o comando de identificacao em App\Support\Judge\ToolchainVersions "
            .'e a promessa em tests/E2E/ToolchainVersionsMatchCatalogTest.'
        );
    }
}
