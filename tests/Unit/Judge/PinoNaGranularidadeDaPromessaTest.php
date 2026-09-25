<?php

namespace Tests\Unit\Judge;

use App\Support\Judge\DockerfileToolchain;
use App\Support\Judge\ToolchainManifest;
use App\Support\Judge\ToolchainRequirement;
use App\Support\Judge\ToolchainVersions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\ToolchainVersionsMatchCatalogTest;
use Tests\TestCase;

/**
 * Issue #408 -- o pino promete o que o rotulo promete, e nada alem disso.
 *
 * ## O que quebrava
 *
 * A #303 fixou todo toolchain por `pacote=versao-rN`. O Alpine nao guarda
 * revisoes antigas: quando publica a proxima, a fixada some, e o proximo
 * build sem cache para em `unable to select packages`. Foram tres em menos de
 * um dia -- `perl=5.42.2-r0` (so recompilacao do Alpine, mesma versao),
 * `ocaml=4.14.3-r0` (patch do upstream) e `erlang27=27.3.4.17-r0`.
 *
 * O pino misturava duas promessas: "o mesmo compilador" (a versao do
 * upstream, que e o que o edital de uma maratona cita e o que pode mudar um
 * veredito) e "o mesmo pacote do Alpine" (a revisao `-rN`). So a primeira
 * importa para julgar, e a segunda e justamente a que quebra sem aviso.
 *
 * ## A regra
 *
 * Todo pino do estagio final usa o operador `~` do `apk`, que casa POR
 * COMPONENTE: `gcc~15` aceita 15.2.0-r5 e 15.3.1-r0, e recusa 1.5 e 150 --
 * medido, `rust~1.9` NAO casa com 1.96.1 nem `python3~3.1` com 3.14.7.
 *
 * - o pacote que E o executavel que a sonda de versao de uma linguagem mede
 *   (`ToolchainVersions`) fica na granularidade que o rotulo promete:
 *   "Python 3.14" -> `python3~3.14`, "Node 24 LTS" -> `nodejs~24`;
 * - o resto (bibliotecas, `bash`, `bubblewrap`, `npm`) fica em
 *   `~MAJOR.MINOR`: revisao e patch entram, minor nao.
 *
 * "E o executavel" quer dizer o PRIMEIRO requisito da procedencia dele. A
 * sonda do Kotlin mede o `kotlinc`, que roda na JVM do `openjdk21-jdk` -- mas
 * o "2.4" do rotulo e do Kotlin, nao da JVM. Sem essa distincao a mesma JVM
 * receberia tres promessas (21 do Java, 2.4 do Kotlin, 1.12 do Clojure).
 *
 * ## Por que isso nao perde a reprodutibilidade da #303
 *
 * O que ela garantia era "(commit, perfil) -> toolchains identicos". O que
 * passa a garantir e "(commit, perfil) -> a versao do upstream que o rotulo
 * promete", e o patch exato de cada veredito fica gravado no proprio
 * julgamento (#402). Um rejulgamento que caia noutro patch diz qual foi.
 */
class PinoNaGranularidadeDaPromessaTest extends TestCase
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
    public function test_nenhum_pino_do_estagio_final_fixa_a_revisao_do_alpine(string $arquivo): void
    {
        $exatos = array_values(array_filter(
            self::imagem($arquivo)->apkSpecs(),
            static fn (string $token): bool => str_contains($token, '='),
        ));

        $this->assertSame(
            [],
            $exatos,
            "{$arquivo}: pino exato no estagio final. A revisao `-rN` some do repositorio a cada recompilacao do Alpine e o build sem cache para -- use `~` na granularidade da promessa (#408)."
        );
    }

    #[DataProvider('imagens')]
    public function test_o_pacote_de_cada_linguagem_promete_exatamente_o_que_o_rotulo_promete(string $arquivo): void
    {
        $imagem = self::imagem($arquivo);
        $specs = $imagem->apkSpecs();
        $divergentes = [];

        foreach (self::promessaPorPacote($imagem) as $pacote => [$prefixo, $extensoes]) {
            $esperado = "{$pacote}~{$prefixo}";

            if ($specs[$pacote] !== $esperado) {
                $divergentes[] = "`{$specs[$pacote]}` deveria ser `{$esperado}` (rotulo de ".implode(', ', $extensoes).')';
            }
        }

        $this->assertSame(
            [],
            $divergentes,
            "{$arquivo}: pino mais fino que a promessa quebra a cada release do Alpine; mais frouxo deixa entrar versao que o rotulo nao anuncia."
        );
    }

    #[DataProvider('imagens')]
    public function test_o_resto_fica_em_major_minor(string $arquivo): void
    {
        $imagem = self::imagem($arquivo);
        $comPromessa = self::promessaPorPacote($imagem);
        $fora = [];

        foreach ($imagem->apkSpecs() as $pacote => $token) {
            if (isset($comPromessa[$pacote]) || ! str_contains($token, '~')) {
                continue;
            }

            if (preg_match('/^[^~]+~\d+\.\d+$/', $token) !== 1) {
                $fora[] = $token;
            }
        }

        $this->assertSame(
            [],
            $fora,
            "{$arquivo}: pacote sem promessa de rotulo fica em `~MAJOR.MINOR` -- revisao e patch entram, minor nao."
        );
    }

    public function test_a_regra_nao_atribui_duas_promessas_ao_mesmo_pacote(): void
    {
        // Se duas linguagens com rotulos diferentes apontassem para o mesmo
        // pacote como SENDO o executavel delas, a regra nao teria resposta.
        // Nao acontece hoje; se acontecer, e aqui que aparece -- e nao como
        // um pino escolhido em silencio por quem editou por ultimo.
        $imagem = self::imagem('Dockerfile.judge');
        $conflitos = [];

        foreach (self::promessasBrutas($imagem) as $pacote => $porExtensao) {
            if (count(array_unique($porExtensao)) > 1) {
                $conflitos[] = $pacote.': '.json_encode($porExtensao);
            }
        }

        $this->assertSame([], $conflitos);
    }

    /**
     * pacote => [prefixo prometido, extensoes que prometem]
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    private static function promessaPorPacote(DockerfileToolchain $imagem): array
    {
        $resultado = [];

        foreach (self::promessasBrutas($imagem) as $pacote => $porExtensao) {
            $resultado[$pacote] = [reset($porExtensao), array_keys($porExtensao)];
        }

        return $resultado;
    }

    /**
     * @return array<string, array<string, string>>  pacote => [extensao => prefixo]
     */
    private static function promessasBrutas(DockerfileToolchain $imagem): array
    {
        $apk = $imagem->apkPackages();
        $procedencia = ToolchainManifest::provenance();
        $porPacote = [];

        foreach (ToolchainVersionsMatchCatalogTest::promessas() as $extensao => [$prefixo]) {
            $sonda = ToolchainVersions::commandFor($extensao);

            if ($sonda === null) {
                continue;
            }

            $primeiro = ($procedencia[ToolchainManifest::executableOf($sonda)] ?? [])[0] ?? null;

            if ($primeiro !== null && $primeiro->kind === ToolchainRequirement::APK && array_key_exists($primeiro->target, $apk)) {
                $porPacote[$primeiro->target][$extensao] = $prefixo;
            }
        }

        return $porPacote;
    }

    private static function imagem(string $arquivo): DockerfileToolchain
    {
        $raiz = dirname(__DIR__, 3);

        return DockerfileToolchain::fromFile($raiz.'/'.$arquivo, $raiz);
    }
}
