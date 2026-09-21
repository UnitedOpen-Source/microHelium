<?php

namespace Tests\Unit\Judge;

use App\Support\Judge\DockerfileToolchain;
use App\Support\Judge\ToolchainManifest;
use App\Support\Judge\ToolchainProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #306, segundo passo -- o perfil promete o que a lista de instalacao
 * entrega, e nao promete nada alem.
 *
 * ## As duas mentiras possiveis, e por que as duas importam
 *
 * Um perfil e uma afirmacao sobre uma imagem que ainda nao existe. Ela pode
 * errar para os dois lados, e os dois custam prova:
 *
 *   - **de menos**: o perfil homologa Kotlin e a lista nao manda instalar o
 *     JDK. O `canJudge()` protege do veredito errado -- o host simplesmente
 *     nao reivindica --, mas a submissao fica sem quem a julgue, e isso so
 *     aparece no dia;
 *   - **de mais**: a lista instala o suficiente para uma linguagem que o
 *     perfil nao admitiu. A imagem entao oferece a equipe uma linguagem que
 *     nao esta no edital, e ninguem decidiu isso.
 *
 * Por isso a assercao central e uma IGUALDADE, e nao uma inclusao:
 * `runs()` (declarado) tem de ser exatamente `satisfiedExtensions()`
 * (calculado a partir do manifesto).
 *
 * ## O que este teste NAO prova
 *
 * Que a imagem construida contem exatamente esses programas. O manifesto
 * descreve a ORDEM de instalacao, nao a arvore de dependencia do `apk` --
 * `apk add npm` traz `nodejs` junto sem que nada aqui saiba. A ausencia que
 * se prova aqui e a da ORDEM ("esta imagem nao recebeu ordem de instalar
 * ghc"), que e a que determina tamanho e tempo de construcao. Quem confere o
 * conteudo real roda dentro da imagem e ja existe
 * (tests/E2E/MultiLanguageJudgingTest.php); rodar aquela suite contra a
 * imagem de um perfil e o passo seguinte da #306, e depende de um build
 * parametrizado que este PR deliberadamente nao faz.
 */
class ToolchainProfileTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Os pinos sao lidos do `Dockerfile.judge` porque ele e o worker
     * autonomo, o que a #53 manda para o rack de outra instituicao -- e e o
     * de quem a #306 mediu o tamanho. Que os tres arquivos digam o MESMO
     * pino ja e exigencia do ToolchainManifestParityTest; aqui isso e
     * premissa, nao se reconfere.
     */
    private static function image(): DockerfileToolchain
    {
        return DockerfileToolchain::fromFile(self::repoRoot().'/Dockerfile.judge', self::repoRoot());
    }

    /**
     * Um caso por perfil, vindo da propria declaracao -- criar um perfil
     * novo cria os casos dele sozinho.
     *
     * @return array<string, array{0: string}>
     */
    public static function profiles(): array
    {
        $cases = [];

        foreach (array_keys(ToolchainProfile::all()) as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    #[DataProvider('profiles')]
    public function test_o_perfil_so_homologa_linguagem_que_o_catalogo_liga(string $name): void
    {
        $profile = ToolchainProfile::named($name);
        self::assertNotNull($profile);

        self::assertSame(
            [],
            $profile->unknownExtensions(),
            "O perfil `{$name}` cita extensao que Language::getDefaultLanguages() nao liga: "
            .implode(', ', $profile->unknownExtensions())
            .'. Um perfil que homologa o que nao existe promete o que a imagem nao vai instalar.',
        );
    }

    /**
     * A assercao central: o declarado e o calculado sao a mesma lista.
     */
    #[DataProvider('profiles')]
    public function test_o_que_o_perfil_roda_e_exatamente_o_que_a_lista_de_instalacao_satisfaz(string $name): void
    {
        $profile = ToolchainProfile::named($name);
        self::assertNotNull($profile);

        $declared = $profile->runs();
        $satisfied = $profile->satisfiedExtensions();

        $missing = array_values(array_diff($declared, $satisfied));
        $extra = array_values(array_diff($satisfied, $declared));

        self::assertSame(
            [],
            $missing,
            "O perfil `{$name}` diz rodar ".implode(', ', $missing)
            .', mas a lista de instalacao dele nao cobre o que essas linguagens exigem'
            .' -- a imagem nasceria sem quem julgasse a submissao.',
        );

        self::assertSame(
            [],
            $extra,
            "A lista de instalacao do perfil `{$name}` ja cobre ".implode(', ', $extra)
            .', que o perfil nao declara. Ou a linguagem entra em `homologadas`/`deGraca`,'
            .' ou a imagem oferece a equipe uma linguagem fora do edital sem ninguem ter decidido.',
        );
    }

    /**
     * Issue #303 -- o pino nao e detalhe de higiene: e o que faz o
     * rejulgamento de uma prova antiga compilar com o mesmo compilador. Uma
     * linha gerada sem pino reintroduz "depende do dia da construcao"
     * exatamente no lugar onde a #306 quer poder reconstruir a imagem depois.
     */
    #[DataProvider('profiles')]
    public function test_todo_pacote_que_o_perfil_manda_instalar_esta_fixado(string $name): void
    {
        $profile = ToolchainProfile::named($name);
        self::assertNotNull($profile);

        $unpinned = array_values(array_filter(
            $profile->apkPackages(self::image()),
            fn (string $package): bool => ! str_contains($package, '='),
        ));

        self::assertSame(
            [],
            $unpinned,
            "O perfil `{$name}` geraria `apk add` sem versao para: ".implode(', ', $unpinned).'.',
        );
    }

    /**
     * Os perfis sao encaixados de proposito: quem escolhe o maior nao perde
     * nada do menor. Sem isso, "subir de perfil" poderia tirar uma linguagem
     * da imagem, que e a forma mais silenciosa de quebrar uma prova.
     */
    public function test_os_perfis_sao_encaixados_do_menor_para_o_maior(): void
    {
        $image = self::image();
        $previous = null;

        foreach (ToolchainProfile::all() as $name => $profile) {
            if ($previous instanceof ToolchainProfile) {
                self::assertSame(
                    [],
                    array_values(array_diff($previous->runs(), $profile->runs())),
                    "O perfil `{$name}` nao contem tudo que `{$previous->name}` roda.",
                );

                self::assertSame(
                    [],
                    array_values(array_diff($previous->apkPackages($image), $profile->apkPackages($image))),
                    "O perfil `{$name}` nao instala tudo que `{$previous->name}` instala.",
                );
            }

            $previous = $profile;
        }
    }

    /**
     * O perfil `completo` nao e uma lista: e o catalogo. Se ele virasse uma
     * lista escrita a mao, ligar uma linguagem nova deixaria de aparecer
     * nele -- e o `completo` e justamente o que descreve as tres imagens de
     * hoje.
     */
    public function test_o_perfil_completo_e_o_catalogo_ativo_e_nao_deixa_pacote_de_fora(): void
    {
        $completo = ToolchainProfile::named('completo');
        self::assertNotNull($completo);

        $active = array_map(
            fn (array $language): string => (string) $language['extension'],
            ToolchainManifest::activeLanguages(),
        );
        sort($active);

        self::assertSame($active, $completo->runs());
        self::assertSame([], $completo->apkPackagesLeftOut(self::image()));
    }

    /**
     * O recorte tem de ser um recorte de verdade.
     *
     * Os pacotes nomeados aqui sao os que a #305 mediu como caros
     * (Haskell/GHC +1442 MiB; Crystal e LDC arrastam LLVM; R, Racket e SBCL
     * sao runtimes inteiros). A afirmacao e sobre a ORDEM DE INSTALACAO --
     * "esta imagem nao recebeu ordem de instalar isto" --, que e a unica
     * forma de ausencia que analise estatica sustenta, e e a que decide
     * tamanho e tempo de build.
     */
    public function test_a_maratona_nao_manda_instalar_o_que_a_maratona_nao_usa(): void
    {
        $maratona = ToolchainProfile::named('maratona');
        self::assertNotNull($maratona);

        $packages = array_map(
            fn (string $package): string => explode('=', $package, 2)[0],
            $maratona->apkPackages(self::image()),
        );

        foreach (['ghc', 'crystal', 'ldc', 'R', 'racket', 'sbcl', 'clisp', 'go', 'rust', 'zig', 'nim', 'dotnet8-sdk'] as $expensive) {
            self::assertNotContains(
                $expensive,
                $packages,
                "O perfil `maratona` manda instalar `{$expensive}`, que nenhuma das cinco linguagens homologadas usa.",
            );
        }

        $completo = ToolchainProfile::named('completo');
        self::assertNotNull($completo);

        self::assertLessThan(
            count($completo->apkPackages(self::image())),
            count($maratona->apkPackages(self::image())),
            'O perfil `maratona` nao esta enxugando nada.',
        );
    }
}
