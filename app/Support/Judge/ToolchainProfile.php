<?php

namespace App\Support\Judge;

/**
 * Issue #306, segundo passo -- o perfil: de "estas sao as linguagens
 * homologadas" para "esta e a lista de instalacao".
 *
 * ## O que o passo anterior deixou pronto, e o que faltava
 *
 * O #353 entregou o manifesto: por linguagem, DE ONDE sai cada programa que
 * o catalogo chama ({@see ToolchainManifest::provenance()}). Ele ja sabia
 * responder "o que o catalogo inteiro exige"; o que ele nao sabia responder
 * e a pergunta que a #306 faz -- "e se a prova homologou apenas CINCO
 * linguagens?".
 *
 * Esta classe responde isso, e nada alem disso. Ela NAO constroi imagem, NAO
 * muda Dockerfile nenhum e NAO acrescenta um `--build-arg`: os tres arquivos
 * seguem instalando o catalogo inteiro. O que ela faz e tornar o recorte
 * COMPUTAVEL e CONFERIVEL, que e o que precisa existir antes de qualquer
 * mudanca de build -- e o que permite medir o ganho sem construir nada.
 *
 * ## O perfil declara duas listas, e a segunda e o ponto
 *
 * `homologadas` e a escolha da organizacao. `deGraca` e o que aquela escolha
 * ARRASTA sem ter pedido, e esta escrito porque descobrir depois e pior:
 *
 *   - quem instala `gcc` ganha `c99_gcc` junto de `c_gcc13`, porque as duas
 *     entradas sao o MESMO compilador com outro `-std`;
 *   - quem instala qualquer coisa ganha `php` e `sed`, porque a imagem base
 *     e `php:8.3-*-alpine` e o `sed` e o do busybox. Nao ha imagem de juiz
 *     mais enxuta que essas duas, e fingir que ha seria mentir sobre o que a
 *     maquina roda;
 *   - quem homologa C# ou JavaScript ganha `sh`, porque os dois chamam
 *     `bash {judge_runtime}/...` e `bash` e o que o `sh` precisa.
 *
 * {@see ToolchainProfileTest} exige que `runs()` seja EXATAMENTE o que a
 * lista de instalacao do perfil satisfaz. As duas direcoes tem dente:
 * faltar uma homologada e a imagem que nao instalou o que prometeu; sobrar
 * uma nao declarada e a imagem que oferece a equipe uma linguagem que nao
 * esta no edital.
 *
 * ## O limite conhecido desta analise
 *
 * O manifesto descreve a ordem de instalacao, nao a arvore de dependencia do
 * `apk`. `apk add npm` traz `nodejs` junto sem que nada aqui saiba disso,
 * entao a imagem real pode conter MAIS programas do que este calculo preve.
 * Por isso a afirmacao que esta classe sustenta e sobre a LISTA DE
 * INSTALACAO -- o que se manda instalar, que e o que determina o tamanho --
 * e nao uma promessa de ausencia sobre o sistema de arquivos final. Quem
 * confere o que a imagem de fato contem roda dentro dela e ja existe
 * (tests/E2E/MultiLanguageJudgingTest.php); rodar aquela suite contra a
 * imagem de um perfil e o passo seguinte, e nao este.
 */
final class ToolchainProfile
{
    /**
     * @param  list<string>|null  $homologadas  null = o catalogo ativo inteiro
     * @param  list<string>  $deGraca
     */
    private function __construct(
        public readonly string $name,
        public readonly string $why,
        private readonly ?array $homologadas,
        private readonly array $deGraca,
    ) {}

    /**
     * Os perfis, do menor para o maior.
     *
     * Sao os quatro que a #306 esbocou. Os nomes das entradas sao os do
     * catalogo, e nao familias inventadas aqui: `java21` e `java25` sao
     * linguagens diferentes para quem submete, e um perfil que dissesse
     * apenas "Java" nao teria como dizer qual das duas a imagem instala.
     *
     * @return array<string, self>
     */
    public static function all(): array
    {
        $profiles = [
            new self(
                'maratona',
                'as cinco da prova tipica da Maratona SBC -- o caso que a #306 usa para medir o desperdicio',
                ['c_gcc13', 'cpp_gpp13', 'java21', 'py3', 'kt'],
                // `c99_gcc`/`cpp14_gpp`/`cpp17_gpp` sao o mesmo `gcc`/`g++`
                // com outro `-std`: ja estao instalados, e recusa-los exigiria
                // apagar entradas do catalogo, nao pacotes da imagem.
                // `php`/`sed` vem da imagem base. `sh` vem do `bash`, que e
                // infraestrutura do juiz (ToolchainManifest::shared()).
                ['c99_gcc', 'cpp14_gpp', 'cpp17_gpp', 'php', 'sed', 'sh'],
            ),
            new self(
                'scripting',
                'a maratona mais o que se usa em seletiva e em prova de iniciantes',
                ['c_gcc13', 'cpp_gpp13', 'java21', 'py3', 'kt', 'js_node24', 'ts', 'rb', 'perl', 'lua', 'awk', 'sh'],
                ['c99_gcc', 'cpp14_gpp', 'cpp17_gpp', 'php', 'sed'],
            ),
            new self(
                'funcional',
                'o `scripting` mais as familias funcional e Lisp -- o perfil que a #305 mostra ser caro',
                [
                    'c_gcc13', 'cpp_gpp13', 'java21', 'py3', 'kt', 'js_node24', 'ts', 'rb', 'perl', 'lua', 'awk', 'sh',
                    'hs', 'ml', 'scm', 'rkt', 'clj', 'scala', 'lisp_sbcl', 'lisp_clisp',
                ],
                ['c99_gcc', 'cpp14_gpp', 'cpp17_gpp', 'php', 'sed'],
            ),
            new self(
                'completo',
                'o catalogo ativo inteiro -- e o que os tres Dockerfiles instalam hoje',
                null,
                [],
            ),
        ];

        $indexed = [];

        foreach ($profiles as $profile) {
            $indexed[$profile->name] = $profile;
        }

        return $indexed;
    }

    public static function named(string $name): ?self
    {
        return self::all()[$name] ?? null;
    }

    /**
     * As linguagens que a organizacao homologou.
     *
     * @return list<string>
     */
    public function homologated(): array
    {
        if ($this->homologadas !== null) {
            return $this->homologadas;
        }

        return array_values(array_map(
            fn (array $language): string => (string) ($language['extension'] ?? ''),
            ToolchainManifest::activeLanguages(),
        ));
    }

    /**
     * O que a escolha arrasta junto, sem ter sido pedido.
     *
     * @return list<string>
     */
    public function free(): array
    {
        return $this->deGraca;
    }

    /**
     * Tudo que a imagem deste perfil roda: o homologado mais o que veio
     * junto. E a afirmacao que o teste confere contra o conjunto de
     * instalacao.
     *
     * @return list<string>
     */
    public function runs(): array
    {
        $runs = array_values(array_unique([...$this->homologated(), ...$this->deGraca]));
        sort($runs);

        return $runs;
    }

    /**
     * As entradas do catalogo correspondentes ao que o perfil homologou.
     *
     * @return list<array<string, mixed>>
     */
    public function languages(): array
    {
        $wanted = array_flip($this->homologated());
        $languages = [];

        foreach (ToolchainManifest::activeLanguages() as $language) {
            if (array_key_exists((string) ($language['extension'] ?? ''), $wanted)) {
                $languages[] = $language;
            }
        }

        return $languages;
    }

    /**
     * Uma extensao homologada que o catalogo ativo nao tem.
     *
     * Um perfil que homologa uma linguagem que nao existe (ou que alguem
     * desligou) nao e um perfil enxuto: e um perfil que promete o que a
     * imagem nao vai instalar, e a submissao fica sem quem a julgue.
     *
     * @return list<string>
     */
    public function unknownExtensions(): array
    {
        $known = [];

        foreach (ToolchainManifest::activeLanguages() as $language) {
            $known[(string) ($language['extension'] ?? '')] = true;
        }

        return array_values(array_filter(
            $this->runs(),
            fn (string $extension): bool => ! array_key_exists($extension, $known),
        ));
    }

    /**
     * Tudo que a imagem deste perfil tem de instalar, sem repeticao: o que
     * esta em toda execucao julgada ({@see ToolchainManifest::shared()})
     * mais o que cada linguagem homologada exige.
     *
     * @return list<ToolchainRequirement>
     */
    public function requirements(): array
    {
        $requirements = [];

        foreach (ToolchainManifest::shared() as $requirement) {
            $requirements[$requirement->key()] = $requirement;
        }

        foreach ($this->languages() as $language) {
            foreach (ToolchainManifest::requirementsFor($language) as $requirement) {
                $requirements[$requirement->key()] = $requirement;
            }
        }

        ksort($requirements);

        return array_values($requirements);
    }

    /**
     * As mesmas exigencias, agrupadas pela procedencia -- que e como a
     * instalacao acontece: os `apk` numa linha so, os downloads um a um, os
     * estagios no topo do arquivo.
     *
     * @return array<string, list<ToolchainRequirement>>
     */
    public function requirementsByKind(): array
    {
        $grouped = [];

        foreach ($this->requirements() as $requirement) {
            $grouped[$requirement->kind][] = $requirement;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * A linha de `apk add` deste perfil, com o pino LIDO da imagem dada.
     *
     * O pino nao mora no manifesto de proposito (#353): quem fixa a versao e
     * o Dockerfile, porque e ele que roda. Aqui ele e lido, e o resultado e
     * literalmente o argumento que uma imagem sob medida passaria ao `apk`.
     *
     * @return list<string> `nome=pino`, em ordem
     */
    public function apkPackages(DockerfileToolchain $image): array
    {
        return ToolchainManifest::resolvedPackagesFor($this->languages(), $image);
    }

    /**
     * Os pacotes que o catalogo ativo manda instalar e ESTE perfil nao.
     *
     * E a medida do recorte, e a unica forma de ausencia que a analise
     * estatica sustenta: nao "este programa nao existe na imagem", que
     * dependeria da arvore do `apk`, mas "esta imagem nao recebeu ordem de
     * instalar isto" -- que e o que determina o tamanho e o tempo de
     * construcao.
     *
     * @return list<string>
     */
    public function apkPackagesLeftOut(DockerfileToolchain $image): array
    {
        $mine = [];

        foreach ($this->apkPackages($image) as $package) {
            $mine[preg_split('/[=~]/', $package, 2)[0]] = true;
        }

        $out = [];

        foreach (ToolchainManifest::resolvedPackagesFor(ToolchainManifest::activeLanguages(), $image) as $package) {
            if (! array_key_exists(preg_split('/[=~]/', $package, 2)[0], $mine)) {
                $out[] = $package;
            }
        }

        return $out;
    }

    /**
     * Issue #306, passo 3 -- o que o `Dockerfile.judge` deixa de instalar
     * quando e construido com este perfil.
     *
     * E a diferenca entre as exigencias do `completo` e as deste perfil,
     * restrita as procedencias que o build sabe pular: `apk`, `download` e
     * `npm`. Cada linha e `procedencia:alvo` (`apk:ghc`, `download:SCALA`,
     * `npm:typescript`), que e o formato que `docker/judge/perfil/perfil`
     * le.
     *
     * O que NAO entra, e por que:
     *
     *   - exigencia de `ToolchainManifest::shared()`: esta em todo perfil por
     *     definicao, entao nunca e diferenca;
     *   - `stage`: `COPY --from` nao e condicional sem mudar o formato que o
     *     DockerfileToolchain le, e o custo dos estagios e medido na spec;
     *   - `invoker`, `repo_file`, `base_image`: sao arquivos pequenos ou a
     *     propria base. Quem impede que eles facam a imagem PARECER capaz do
     *     que nao e e a sonda, restrita ao prometido
     *     (MachineCapabilities::detect()).
     *
     * @return list<string>
     */
    public function cuts(): array
    {
        $mine = [];

        foreach ($this->requirements() as $requirement) {
            $mine[$requirement->key()] = true;
        }

        $skippable = [ToolchainRequirement::APK, ToolchainRequirement::DOWNLOAD, ToolchainRequirement::NPM];
        $cuts = [];

        foreach ((self::named('completo') ?? $this)->requirements() as $requirement) {
            if (in_array($requirement->kind, $skippable, true) && ! array_key_exists($requirement->key(), $mine)) {
                $cuts[] = $requirement->key();
            }
        }

        sort($cuts);

        return $cuts;
    }

    /**
     * As linguagens do catalogo ativo que a lista de instalacao deste perfil
     * ja satisfaz -- ou seja, o que a imagem roda, calculado em vez de
     * declarado.
     *
     * Uma linguagem entra aqui quando TODA chave do manifesto que os comandos
     * dela usam esta coberta. As exigencias de imagem base nao contam contra
     * ninguem: elas vem prontas em qualquer imagem de juiz.
     *
     * @return list<string>
     */
    public function satisfiedExtensions(): array
    {
        $installed = [];

        foreach ($this->requirements() as $requirement) {
            $installed[$requirement->key()] = true;
        }

        $provenance = ToolchainManifest::provenance();
        $satisfied = [];

        foreach (ToolchainManifest::activeLanguages() as $language) {
            $ok = true;

            foreach (ToolchainManifest::keysUsedBy($language) as $key) {
                // Chave sem procedencia declarada: o manifesto nao sabe dizer
                // o que instalar, entao nenhum perfil pode afirmar que roda.
                // ToolchainManifestParityTest ja reprova esse caso na fonte.
                if (! array_key_exists($key, $provenance)) {
                    $ok = false;
                    break;
                }

                foreach ($provenance[$key] as $requirement) {
                    if ($requirement->kind === ToolchainRequirement::BASE_IMAGE) {
                        continue;
                    }

                    if (! array_key_exists($requirement->key(), $installed)) {
                        $ok = false;
                        break 2;
                    }
                }
            }

            if ($ok) {
                $satisfied[] = (string) ($language['extension'] ?? '');
            }
        }

        sort($satisfied);

        return $satisfied;
    }
}
