<?php

namespace Tests\Unit\Judge;

use App\Models\Language;
use App\Support\Judge\DockerfileToolchain;
use App\Support\Judge\ToolchainManifest;
use App\Support\Judge\ToolchainRequirement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #306, primeiro passo -- a paridade entre os tres Dockerfiles deixa de
 * depender de atencao humana.
 *
 * ## O que este teste impede
 *
 * A #302 aconteceu de verdade: Scratch e Portugol Studio entraram so no
 * `Dockerfile.judge`. As duas imagens que julgam pelo caminho da fila
 * (`Dockerfile` e `Dockerfile.dev`, que o docker-compose roda nos servicos
 * `queue` e `scheduler`) nao tinham o `scratch-run` -- e uma submissao
 * correta virava `CE` com `command not found`, dependendo so de qual worker
 * pegasse o trabalho. O #344 corrigiu; nada impedia de voltar.
 *
 * Este teste e o que impede. Para cada linguagem que o catalogo liga por
 * padrao, ele exige que as TRES imagens mandem instalar o que aquela
 * linguagem precisa, e que mandem instalar A MESMA VERSAO.
 *
 * ## Por que e estatico
 *
 * Construir as tres imagens levaria dezenas de minutos por execucao. Ler os
 * tres arquivos leva milissegundos e roda na suite de sempre -- a troca que
 * a propria #302 sugeriu. O que ele NAO responde e se o `apk` resolveu o
 * pacote: isso ja e coberto dentro da imagem por
 * tests/E2E/ToolchainVersionsMatchCatalogTest.php (a versao instalada e a
 * prometida?) e por tests/E2E/MultiLanguageJudgingTest.php (a linguagem
 * compila e recebe AC?). O buraco entre os dois era "esta imagem nem recebeu
 * ordem de instalar isto", e e esse que se fecha aqui.
 *
 * ## Por que ele nao e uma segunda lista escrita a mao
 *
 * Nada aqui nem no manifesto repete o Dockerfile. A ligacao
 * linguagem -> programa e derivada dos comandos do proprio catalogo; o pino
 * de versao e LIDO dos Dockerfiles. O unico dado declarado a mao e
 * "de onde sai este programa" (App\Support\Judge\ToolchainManifest), que e a
 * informacao que hoje nao existe escrita em lugar nenhum -- e cuja ausencia
 * e a #302.
 */
class ToolchainManifestParityTest extends TestCase
{
    /**
     * As tres imagens que compilam e executam codigo submetido.
     *
     * `Dockerfile` e a da aplicacao: o docker-compose.yml a roda nos
     * servicos `queue` e `scheduler`, e e la que o JudgeRunJob julga.
     * `Dockerfile.dev` e a mesma coisa no desenvolvimento. `Dockerfile.judge`
     * e o worker autonomo do `autojudge:start`.
     *
     * @return list<string>
     */
    private static function dockerfiles(): array
    {
        return ['Dockerfile', 'Dockerfile.dev', 'Dockerfile.judge'];
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function image(string $dockerfile): DockerfileToolchain
    {
        return DockerfileToolchain::fromFile(self::repoRoot().'/'.$dockerfile, self::repoRoot());
    }

    /**
     * Uma linha por linguagem ligada no catalogo.
     *
     * Deliberadamente sem numero colado: o provedor devolve o que o catalogo
     * tiver, e ligar uma linguagem nova cria um caso novo sozinho.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function activeLanguages(): array
    {
        $cases = [];

        foreach (ToolchainManifest::activeLanguages() as $language) {
            $cases[(string) $language['extension']] = [$language];
        }

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $language
     */
    #[DataProvider('activeLanguages')]
    public function test_as_tres_imagens_instalam_o_que_a_linguagem_exige(array $language): void
    {
        $requirements = ToolchainManifest::requirementsFor($language);

        $this->assertNotSame(
            [],
            $requirements,
            "{$language['extension']}: o manifesto nao exige nada para esta linguagem, o que so pode ser engano -- "
            .'toda linguagem ligada chama algum programa.'
        );

        foreach (self::dockerfiles() as $dockerfile) {
            $image = self::image($dockerfile);

            foreach ($requirements as $requirement) {
                $this->assertTrue(
                    $image->satisfies($requirement),
                    "{$dockerfile} nao instala o que `{$language['extension']}` ({$language['name']}) precisa: "
                    .$requirement->describe()."\n"
                    .'E assim que a #302 aconteceu: a linguagem fica ligada no catalogo, funciona na imagem em que '
                    ."alguem lembrou de instala-la, e vira `CE` na outra.\n"
                    .'Ou acrescente a instalacao a este Dockerfile, ou desligue a linguagem no catalogo.'
                );
            }
        }
    }

    /**
     * O mesmo pacote, a mesma versao, nas tres imagens.
     *
     * Instalar `ghc` nas tres e metade do problema; instalar tres GHC
     * diferentes e a outra metade. Com julgamento distribuido (#53) o
     * veredito passaria a depender de qual worker pegou a submissao, que e a
     * forma silenciosa do mesmo erro.
     */
    public function test_os_pinos_sao_os_mesmos_nos_tres_dockerfiles(): void
    {
        $images = [];

        foreach (self::dockerfiles() as $dockerfile) {
            $images[$dockerfile] = self::image($dockerfile);
        }

        foreach (self::allRequirements() as $requirement) {
            $pins = [];

            foreach ($images as $dockerfile => $image) {
                $pins[$dockerfile] = $image->pinFor($requirement);
            }

            $this->assertLessThanOrEqual(
                1,
                count(array_unique($pins, SORT_REGULAR)),
                $requirement->describe().' esta fixado em versoes diferentes: '
                .json_encode($pins, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }
    }

    /**
     * O mesmo invocador tem de chamar o mesmo programa nas tres imagens.
     *
     * Existir com o nome certo nao basta. Enquanto este teste era escrito, o
     * #351 trocou o `/usr/local/bin/portugol-studio` do `Dockerfile.judge`
     * (do `Console` com `-no-wait`, que sai 0 quando o programa morre, para o
     * `ExecutaPortugol`) e deixou as duas imagens da aplicacao com o
     * invocador antigo. As tres tinham o arquivo, com o nome certo, e uma
     * delas ainda transformava erro de execucao em `WA` mudo (#301) -- a
     * forma da #302 uma camada mais fundo, e invisivel para qualquer
     * checagem de presenca.
     *
     * So a linha que CRIA o invocador e comparada; as verificacoes de fumaca
     * ao redor dela sao diferentes de proposito entre as imagens.
     */
    public function test_os_invocadores_sao_os_mesmos_nos_tres_dockerfiles(): void
    {
        $images = [];

        foreach (self::dockerfiles() as $dockerfile) {
            $images[$dockerfile] = self::image($dockerfile);
        }

        foreach (self::allRequirements() as $requirement) {
            if ($requirement->kind !== ToolchainRequirement::INVOKER) {
                continue;
            }

            $recipes = [];

            foreach ($images as $dockerfile => $image) {
                $recipes[$dockerfile] = $image->invokerRecipe($requirement->target);
            }

            $this->assertCount(
                1,
                array_unique($recipes, SORT_REGULAR),
                "/usr/local/bin/{$requirement->target} e criado de formas diferentes nas tres imagens:\n  "
                .implode("\n  ", array_map(
                    fn (string $file, ?string $recipe) => $file.': '.($recipe ?? '(nao existe)'),
                    array_keys($recipes),
                    $recipes
                ))
                ."\nO mesmo nome chamando programas diferentes e a #302 uma camada mais fundo: a linguagem "
                .'aparece instalada nas tres e se comporta de um jeito em cada uma.'
            );
        }
    }

    /**
     * Issue #303 -- tudo que compila ou executa codigo de competidor esta
     * fixado.
     *
     * O criterio ja estava escrito nos tres Dockerfiles, em comentario. O
     * manifesto e o que torna possivel conferi-lo: o conjunto "pacotes que
     * uma linguagem exige" agora e uma lista que codigo consegue percorrer,
     * e nao uma leitura da lista inteira do `apk add` -- onde `git` e
     * `curl`, que so constroem a imagem, convivem com o `gcc`, que julga.
     */
    public function test_todo_pacote_que_julga_esta_com_versao_fixada(): void
    {
        foreach (self::dockerfiles() as $dockerfile) {
            $image = self::image($dockerfile);

            foreach (self::allRequirements() as $requirement) {
                if ($requirement->kind !== ToolchainRequirement::APK) {
                    continue;
                }

                $this->assertNotNull(
                    $image->pinFor($requirement),
                    "{$dockerfile} instala `{$requirement->target}` sem fixar a versao, e ele esta no caminho de "
                    ."execucao de codigo submetido.\nUm veredito nao pode depender do dia em que a imagem foi "
                    .'construida (#303).'
                );
            }
        }
    }

    /**
     * Os ARGs que mais de um Dockerfile define tem de valer o mesmo.
     *
     * E onde moram as versoes e os sha256 do que e baixado ou compilado do
     * fonte (Kotlin, Scala, Groovy, Dart, FPC, TypeScript, SWI-Prolog, GNU
     * Prolog, scratch-run, Portugol Studio). Dois arquivos com o mesmo ARG em
     * valores diferentes sao duas imagens com toolchains diferentes se
     * dizendo iguais.
     *
     * Os ARGs que existem num arquivo so nao entram: o `JPLAG_*` e da imagem
     * da aplicacao, que roda a deteccao de plagio, e nao faz sentido no
     * worker.
     */
    public function test_os_args_compartilhados_valem_o_mesmo_nos_tres(): void
    {
        $args = [];

        foreach (self::dockerfiles() as $dockerfile) {
            foreach (self::image($dockerfile)->args() as $name => $value) {
                $args[$name][$dockerfile] = $value;
            }
        }

        foreach ($args as $name => $values) {
            if (count($values) < 2) {
                continue;
            }

            $this->assertCount(
                1,
                array_unique($values),
                "o ARG {$name} tem valores diferentes entre os Dockerfiles: "
                .json_encode($values, JSON_UNESCAPED_SLASHES)
            );
        }
    }

    /**
     * O que nao e de uma linguagem so, mas esta no caminho de toda execucao
     * julgada -- o confinamento, principalmente.
     */
    public function test_as_exigencias_compartilhadas_estao_nas_tres_imagens(): void
    {
        foreach (self::dockerfiles() as $dockerfile) {
            $image = self::image($dockerfile);

            foreach (ToolchainManifest::shared() as $requirement) {
                $this->assertTrue(
                    $image->satisfies($requirement),
                    "{$dockerfile} nao instala ".$requirement->describe()
                );
            }
        }
    }

    /**
     * Toda linguagem ligada tem de ter sua procedencia declarada.
     *
     * Sem isto o manifesto ficaria verde por omissao: ligar uma linguagem
     * nova sem dizer de onde sai o compilador dela nao geraria exigencia
     * nenhuma, e o teste acima nao teria o que conferir. E exatamente assim
     * que a #302 passou batido.
     */
    public function test_toda_linguagem_ligada_tem_procedencia_declarada(): void
    {
        $uncovered = ToolchainManifest::uncoveredKeys();

        $lines = [];

        foreach ($uncovered as $key => $extensions) {
            $lines[] = "  {$key} (usado por ".implode(', ', array_unique($extensions)).')';
        }

        $this->assertSame(
            [],
            $lines,
            "o catalogo liga linguagens que chamam programas que o manifesto nao sabe de onde vem:\n"
            .implode("\n", $lines)."\n"
            .'Declare a procedencia em App\Support\Judge\ToolchainManifest::provenance().'
        );
    }

    /**
     * E o contrario: o manifesto nao descreve programa que o catalogo nao
     * chama.
     *
     * Uma entrada orfa aqui e a mesma doenca pelo outro lado -- uma exigencia
     * que ninguem mais usa, mantida a mao, dizendo que a imagem precisa de
     * algo que nenhuma linguagem pede.
     */
    public function test_o_manifesto_nao_descreve_programa_que_o_catalogo_nao_chama(): void
    {
        $used = [];

        foreach (Language::getDefaultLanguages() as $language) {
            foreach (ToolchainManifest::keysUsedBy($language) as $key) {
                $used[$key] = true;
            }
        }

        $orphans = array_values(array_diff(array_keys(ToolchainManifest::provenance()), array_keys($used)));

        $this->assertSame(
            [],
            $orphans,
            'o manifesto declara procedencia de programas que comando nenhum do catalogo chama: '
            .implode(', ', $orphans)
        );
    }

    /**
     * O manifesto e legivel por codigo, e nao so por gente.
     *
     * Este caso nao guarda nenhuma regra nova: ele demonstra o que os passos
     * seguintes da #306 (perfis, e depois geracao por prova) vao consumir --
     * dado um conjunto de linguagens homologadas, a lista de pacotes JA COM
     * PINO, que e literalmente o argumento de `apk add` de uma imagem sob
     * medida.
     *
     * A prova de que isso serve para alguma coisa esta na segunda asserção:
     * uma prova de cinco linguagens nao arrasta o GHC, que sozinho pesa
     * +1442 MiB na medicao da #305.
     */
    public function test_o_manifesto_resolve_uma_prova_de_cinco_linguagens_em_pacotes_com_pino(): void
    {
        $homologadas = array_values(array_filter(
            ToolchainManifest::activeLanguages(),
            fn (array $language) => in_array($language['extension'], ['c_gcc13', 'cpp_gpp13', 'java21', 'py3', 'kt'], true)
        ));

        $packages = ToolchainManifest::resolvedPackagesFor($homologadas, self::image('Dockerfile.judge'));

        foreach ($packages as $package) {
            $this->assertMatchesRegularExpression(
                '/^[^=~]+[=~].+$/',
                $package,
                "a resolucao devolveu `{$package}` sem pino, e uma imagem sob medida sem pino nao e reproduzivel (#303)"
            );
        }

        $names = array_map(fn (string $package) => preg_split('/[=~]/', $package, 2)[0], $packages);

        $this->assertContains('gcc', $names);
        $this->assertContains('openjdk21-jdk', $names);
        $this->assertNotContains('ghc', $names, 'uma prova sem Haskell nao deveria carregar o GHC');
        $this->assertNotContains('racket', $names);
    }

    /**
     * Toda exigencia de toda linguagem ligada, sem repeticao.
     *
     * @return list<ToolchainRequirement>
     */
    private static function allRequirements(): array
    {
        $requirements = [];

        foreach (ToolchainManifest::shared() as $requirement) {
            $requirements[$requirement->key()] = $requirement;
        }

        foreach (ToolchainManifest::activeLanguages() as $language) {
            foreach (ToolchainManifest::requirementsFor($language) as $requirement) {
                $requirements[$requirement->key()] = $requirement;
            }
        }

        return array_values($requirements);
    }
}
