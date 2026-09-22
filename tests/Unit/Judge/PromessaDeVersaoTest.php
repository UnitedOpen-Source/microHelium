<?php

namespace Tests\Unit\Judge;

use App\Models\Language;
use PHPUnit\Framework\TestCase;
use Tests\E2E\ToolchainVersionsMatchCatalogTest;

/**
 * Issues #303 e #305 -- a parte da conferencia de versao que NAO precisa da
 * imagem.
 *
 * ## O defeito que esta classe existe para impedir
 *
 * A #305 mediu, dentro da imagem, tres entradas do catalogo que prometiam
 * uma versao e rodavam outra:
 *
 *     ativar java17    -> javac 21.0.12   (nao 17)
 *     ativar js_node20 -> node v24.18.1   (nao 20)
 *     ativar js_node22 -> node v24.18.1   (nao 22)
 *
 * O comentario do catalogo dizia que essas entradas estavam desligadas
 * porque seleciona-las "would silently fail". Medido, elas NAO falhavam:
 * rodavam a versao errada em silencio, que e pior -- a equipe escolhe "Node
 * 20 LTS", recebe Node 24, e nada no sistema registra a diferenca. Numa
 * maratona a versao do compilador e parte do edital.
 *
 * O Java ja foi consertado (#307): as tres entradas passaram a chamar
 * `/usr/lib/jvm/java-NN-openjdk/bin/javac`, caminho absoluto por versao, e
 * `java17` deixou de mentir -- passou a falhar, porque o `openjdk17-jdk` nao
 * esta instalado. O que nao existia era quem IMPEDISSE a volta.
 *
 * ## Por que aqui e nao dentro da imagem
 *
 * `tests/E2E/ToolchainVersionsMatchCatalogTest` faz a metade dinamica da
 * pergunta -- roda o toolchain e compara com o prometido --, e so pode rodar
 * onde o toolchain existe, ou seja dentro da imagem do juiz, no job mais
 * caro do CI. A metade que sobra e estatica e esta aqui, na suite de todo
 * PR, e sao tres afirmacoes que nao dependem de nenhum binario instalado:
 *
 *   1. duas entradas LIGADAS que mandam executar exatamente o mesmo comando
 *      nao podem prometer versoes diferentes -- porque e literalmente o
 *      mesmo programa, entao no maximo uma delas esta dizendo a verdade;
 *   2. um numero dentro de um caminho absoluto do comando (o mecanismo do
 *      "lado a lado") tem de ser um numero que o rotulo promete;
 *   3. a tabela daquele teste e uma COPIA do rotulo do catalogo, escrita a
 *      mao. Se o catalogo for renomeado e a tabela nao, a conferencia de
 *      dentro da imagem continua verde conferindo a versao ANTIGA -- um
 *      verde contra um mecanismo que ja nao mede o que promete medir.
 *
 * O (1) e o que reprova a reativacao as cegas de `js_node20`/`js_node22`: o
 * Alpine 3.24 publica um unico Node, e o teste fica vermelho no instante em
 * que alguem os liga ao lado do `js_node24` sem antes dar a cada um um
 * comando que distinga a versao. Medido em 21/09/2026, em `php:8.3-cli-alpine`
 * (Alpine 3.24.2, aarch64):
 *
 *     apk search -x nodejs nodejs-current  ->  nodejs-24.18.1-r0
 *                                              nodejs-current-26.5.1-r0
 *     apk info --provides nodejs-current   ->  nodejs
 *                                              cmd:node=26.5.1-r0
 *     apk add --simulate nodejs nodejs-current
 *                                          ->  instala SO nodejs-current
 *
 * Ou seja: nao e so que o Alpine nao publica `nodejs20`/`nodejs22`. Ele
 * modela o Node como um unico provedor de `cmd:node`, entao pedir dois
 * instala um e o outro some -- a mesma falha silenciosa, agora na camada do
 * pacote. Node lado a lado e trabalho do Lote D da #305 (artefato de fora do
 * Alpine, fixado por versao e hash), e nao um `apk add`.
 */
class PromessaDeVersaoTest extends TestCase
{
    /**
     * Duas entradas ligadas com o MESMO par de comandos executam o mesmo
     * programa; logo, ou prometem a mesma versao, ou uma delas mente.
     *
     * Hoje isto esta verde, e o valor esta no dia em que deixar de estar:
     * `js_node20`, `js_node22` e `js_node24` tem comandos identicos e
     * prometem 20, 22 e 24. Enquanto as duas primeiras estao desligadas elas
     * nao enganam ninguem -- o formulario de envio nao as oferece. Basta um
     * `is_active => true` para este caso ficar vermelho, que e exatamente o
     * momento em que a mentira passaria a existir.
     *
     * A comparacao e entre LIGADAS porque e o que a equipe pode escolher.
     * Uma entrada desligada nao e uma promessa: e uma receita.
     */
    public function test_duas_entradas_ligadas_com_o_mesmo_comando_nao_podem_prometer_versoes_diferentes(): void
    {
        $porComando = [];

        foreach (self::ligadas() as $language) {
            $chave = ($language['compile_command'] ?? '')."\n".($language['run_command'] ?? '');
            $porComando[$chave][] = $language;
        }

        $mentiras = [];

        foreach ($porComando as $grupo) {
            if (count($grupo) < 2) {
                continue;
            }

            $promessas = [];

            foreach ($grupo as $language) {
                $promessas[(string) $language['extension']] = self::versoesEm((string) $language['name']);
            }

            $distintas = array_unique(array_map(
                static fn (array $versoes): string => implode('.', $versoes),
                $promessas
            ));

            if (count($distintas) < 2) {
                continue;
            }

            $descricao = [];

            foreach ($grupo as $language) {
                $descricao[] = sprintf(
                    '  %s promete "%s"',
                    (string) $language['extension'],
                    (string) $language['name']
                );
            }

            $mentiras[] = sprintf(
                "estas entradas ligadas executam o MESMO comando e prometem versoes diferentes:\n%s\n"
                ."  compile: %s\n  run:     %s",
                implode("\n", $descricao),
                (string) ($grupo[0]['compile_command'] ?? ''),
                (string) ($grupo[0]['run_command'] ?? '')
            );
        }

        $this->assertSame(
            [],
            $mentiras,
            "Uma entrada do catalogo promete uma versao e roda outra -- a #305 mediu\n"
            ."isso acontecendo (ativar `js_node20` rodava node v24.18.1). Duas entradas\n"
            ."so podem compartilhar comando se prometerem a mesma versao; para oferecer\n"
            ."duas versoes de verdade, de a cada uma um comando que as distinga (e o que\n"
            ."as tres entradas de Java fazem, por caminho absoluto).\n\n"
            .implode("\n\n", $mentiras)
        );
    }

    /**
     * O mecanismo do "lado a lado" e o caminho absoluto por versao, e ele so
     * funciona se o numero do caminho for o numero do rotulo.
     *
     * Este e o caso `java17` da #305, escrito como teste: uma entrada
     * chamada `Java (OpenJDK 17 LTS)` cujo comando aponte para
     * `/usr/lib/jvm/java-21-openjdk/bin/javac` reprova aqui, na suite rapida,
     * sem precisar de JDK nenhum instalado.
     *
     * Vale para entrada desligada tambem: o caminho errado numa entrada
     * desligada e uma armadilha armada para quem a ligar depois.
     */
    public function test_o_numero_no_caminho_absoluto_do_comando_e_um_numero_que_o_rotulo_promete(): void
    {
        $divergencias = [];

        foreach (Language::getDefaultLanguages() as $language) {
            $rotulo = (string) ($language['name'] ?? '');
            $prometidas = self::versoesEm($rotulo);

            foreach (self::caminhosAbsolutosEm($language) as $caminho) {
                foreach (self::versoesNoCaminho($caminho) as $versao) {
                    if (self::coberta($versao, $prometidas)) {
                        continue;
                    }

                    $divergencias[] = sprintf(
                        '%s: o rotulo diz "%s" (versao %s) e o comando chama `%s` (versao %s)',
                        (string) ($language['extension'] ?? '?'),
                        $rotulo,
                        $prometidas === [] ? 'nenhuma' : implode('/', $prometidas),
                        $caminho,
                        $versao
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $divergencias,
            "O caminho absoluto e o que torna \"manter a versao antiga ao lado da nova\"\n"
            ."verdadeiro (#305). Um numero no caminho que o rotulo nao promete e a\n"
            ."entrada rodando uma versao e anunciando outra -- medido na #305 com\n"
            ."`java17`, que rodava `javac 21.0.12`.\n\n"
            .implode("\n", $divergencias)
        );
    }

    /**
     * A tabela de {@see ToolchainVersionsMatchCatalogTest} guarda uma copia
     * do rotulo do catalogo. Copia que ninguem confere e copia que envelhece.
     *
     * O buraco e concreto: renomear `C (GCC 15)` para `C (GCC 16)` no
     * catalogo e esquecer a tabela deixa o teste de dentro da imagem VERDE,
     * conferindo `gcc -dumpversion` contra o 15 que ninguem mais promete.
     * Ele nao mede mais o que diz medir, e nada avisa.
     */
    public function test_a_receita_de_versao_repete_o_rotulo_que_o_catalogo_anuncia(): void
    {
        $catalogo = self::porExtensao();
        $divergencias = [];

        foreach (ToolchainVersionsMatchCatalogTest::versionedLanguages() as $linha) {
            [$extensao, , , $rotulo] = $linha;

            if (! isset($catalogo[$extensao])) {
                continue;
            }

            $nome = (string) $catalogo[$extensao]['name'];

            if ($nome !== $rotulo) {
                $divergencias[] = sprintf(
                    '%s: a tabela guarda "%s" e o catalogo anuncia "%s"',
                    $extensao,
                    $rotulo,
                    $nome
                );
            }
        }

        $this->assertSame(
            [],
            $divergencias,
            "O rotulo copiado na tabela de tests/E2E/ToolchainVersionsMatchCatalogTest.php\n"
            ."envelheceu em relacao ao catalogo. Enquanto os dois discordam, aquele teste\n"
            ."confere a versao que o catalogo NAO promete mais, e passa.\n\n"
            .implode("\n", $divergencias)
        );
    }

    /**
     * E a versao que a tabela manda conferir tem de ser a versao que o rotulo
     * promete -- nao basta o texto do rotulo bater.
     *
     * `promised` e um PREFIXO de versao (`8.0` para `C# (.NET 8)`, `4.0` para
     * `Groovy 4`), entao a exigencia e de coerencia e nao de igualdade: um
     * tem de ser prefixo do outro, componente a componente. `15` contra
     * `C (GCC 16)` reprova; `8` contra `C# (.NET 8)` passa.
     *
     * Rotulo sem numero nenhum (`Pascal (FPC)`, `Tcl`) nao promete versao, e
     * a tabela pode conferir o que quiser: nao ha o que contradizer.
     */
    public function test_a_versao_que_a_receita_confere_e_coerente_com_o_rotulo(): void
    {
        $catalogo = self::porExtensao();
        $divergencias = [];

        foreach (ToolchainVersionsMatchCatalogTest::versionedLanguages() as $linha) {
            [$extensao, , $prometida] = $linha;

            if (! isset($catalogo[$extensao])) {
                continue;
            }

            $doRotulo = self::versoesEm((string) $catalogo[$extensao]['name']);

            if ($doRotulo === []) {
                continue;
            }

            foreach ($doRotulo as $versao) {
                if (self::compativel($versao, $prometida)) {
                    continue 2;
                }
            }

            $divergencias[] = sprintf(
                '%s: a tabela confere a versao %s e o rotulo "%s" promete %s',
                $extensao,
                $prometida,
                (string) $catalogo[$extensao]['name'],
                implode('/', $doRotulo)
            );
        }

        $this->assertSame([], $divergencias, implode("\n", $divergencias));
    }

    /**
     * E a tabela nao pode conferir uma entrada que o catalogo nao tem.
     *
     * Uma linha orfa nao reprova nada la dentro -- ela so nunca roda, e a
     * cobertura que ela aparentava dar nao existe.
     */
    public function test_a_receita_de_versao_so_nomeia_entradas_que_o_catalogo_tem(): void
    {
        $catalogo = self::porExtensao();
        $orfas = [];

        foreach (ToolchainVersionsMatchCatalogTest::versionedLanguages() as $chave => $linha) {
            [$extensao] = $linha;

            if ($chave !== $extensao) {
                $orfas[] = sprintf('a chave "%s" nao e a extensao "%s" da propria linha', $chave, $extensao);
            }

            if (! isset($catalogo[$extensao])) {
                $orfas[] = sprintf('"%s" nao existe em Language::getDefaultLanguages()', $extensao);
            }
        }

        $this->assertSame([], $orfas, implode("\n", $orfas));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function ligadas(): array
    {
        $ligadas = [];

        foreach (Language::getDefaultLanguages() as $language) {
            if (($language['is_active'] ?? false) === true) {
                $ligadas[] = $language;
            }
        }

        return $ligadas;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function porExtensao(): array
    {
        $porExtensao = [];

        foreach (Language::getDefaultLanguages() as $language) {
            $porExtensao[(string) $language['extension']] = $language;
        }

        return $porExtensao;
    }

    /**
     * Os numeros de versao que um rotulo anuncia, na ordem em que aparecem.
     *
     * `C++14 (G++ 15)` devolve ['14', '15'] -- o catalogo usa numero para
     * padrao da linguagem e para versao do compilador no mesmo nome, e
     * distinguir os dois exigiria uma quarta coluna escrita a mao. Aceitar
     * qualquer um dos dois deixa o teste mais fraco e nunca falso-positivo,
     * que e a troca certa para uma guarda que roda em todo PR.
     *
     * @return list<string>
     */
    private static function versoesEm(string $rotulo): array
    {
        preg_match_all('/\d+(?:\.\d+)*/', $rotulo, $encontrados);

        return array_values(array_unique($encontrados[0]));
    }

    /**
     * Os tokens de um comando que sao caminho absoluto.
     *
     * @param  array<string, mixed>  $language
     * @return list<string>
     */
    private static function caminhosAbsolutosEm(array $language): array
    {
        $caminhos = [];

        foreach (['compile_command', 'run_command'] as $campo) {
            $comando = (string) ($language[$campo] ?? '');

            foreach (preg_split('/\s+/', trim($comando)) ?: [] as $token) {
                if (str_starts_with($token, '/') && ! str_contains($token, '{')) {
                    $caminhos[$token] = true;
                }
            }
        }

        return array_keys($caminhos);
    }

    /**
     * Os numeros de versao de um caminho, lidos segmento a segmento.
     *
     * So conta numero precedido de separador (ou de um `v`), que e como um
     * diretorio de toolchain versionado se escreve: `java-25-openjdk`,
     * `node-v20`, `dotnet/8.0`. Assim `x86_64` nao vira "versao 64", e o
     * teste nao precisa de uma lista de excecoes para conviver com numeros
     * que nao sao versao.
     *
     * @return list<string>
     */
    private static function versoesNoCaminho(string $caminho): array
    {
        $versoes = [];

        foreach (explode('/', $caminho) as $segmento) {
            if ($segmento === '') {
                continue;
            }

            preg_match_all('/(?<![A-Za-z0-9_])v?(\d+(?:\.\d+)*)/', $segmento, $encontrados);

            foreach ($encontrados[1] as $versao) {
                $versoes[$versao] = true;
            }
        }

        return array_keys($versoes);
    }

    /**
     * @param  list<string>  $prometidas
     */
    private static function coberta(string $versao, array $prometidas): bool
    {
        foreach ($prometidas as $prometida) {
            if (self::compativel($versao, $prometida)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Duas versoes sao compativeis quando uma e prefixo da outra, componente
     * a componente: `8` e `8.0` sim, `8` e `10` nao, `1.2` e `1.26` nao.
     */
    private static function compativel(string $a, string $b): bool
    {
        $pa = explode('.', $a);
        $pb = explode('.', $b);
        $comum = min(count($pa), count($pb));

        for ($i = 0; $i < $comum; $i++) {
            if ($pa[$i] !== $pb[$i]) {
                return false;
            }
        }

        return true;
    }
}
