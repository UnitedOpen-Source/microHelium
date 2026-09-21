<?php

namespace App\Support\Judge;

use App\Models\Language;

/**
 * Issue #306, primeiro passo -- o manifesto: o que cada linguagem do
 * catalogo exige para ser INSTALADA.
 *
 * ## Por que isto existe
 *
 * Hoje o vinculo "linguagem -> instalacao" so existe na cabeca de quem edita
 * o Dockerfile. A #302 e o que acontece quando ele se perde: Scratch e
 * Portugol Studio entraram so no `Dockerfile.judge`, e pelo caminho da fila
 * (que roda a imagem da aplicacao) uma submissao correta virava `CE` com
 * `command not found`. Foi corrigida no #344 -- mas a paridade entre os tres
 * arquivos continuou sendo mantida por atencao humana.
 *
 * Este manifesto, mais o teste de paridade que o consome
 * (tests/Unit/Judge/ToolchainManifestParityTest.php), troca a atencao humana
 * por uma checagem estatica: barata o bastante para rodar na suite normal, e
 * suficiente para reprovar a #302 antes de ela chegar na prova.
 *
 * ## Onde mora cada verdade (e por que nao ha uma segunda copia de nenhuma)
 *
 * Um manifesto que repetisse o Dockerfile seria uma segunda lista mantida a
 * mao -- o problema que ele veio resolver. Entao a informacao e repartida, e
 * cada pedaco tem UM dono:
 *
 *   1. **Que programa cada linguagem usa** -- ja esta no catalogo, nos
 *      proprios `compile_command`/`run_command`. Aqui isso e DERIVADO
 *      ({@see dependenciesOf()}), nunca redigitado. Ligar `Rust (1.96)` ao
 *      `rustc` nao e decisao deste arquivo: e o que o comando diz.
 *   2. **De onde sai cada programa** -- e a unica coisa que este arquivo
 *      declara, na tabela {@see provenance()}. Esta informacao nao existe em
 *      lugar nenhum hoje; e exatamente a que se perdeu na #302.
 *   3. **Em que versao** -- continua nos Dockerfiles, onde ela e executada.
 *      O manifesto nao repete `ghc=9.10.3-r2`; o pino e LIDO
 *      ({@see DockerfileToolchain::pinFor()}) e o teste exige que os tres
 *      arquivos concordem. Se o pino tambem morasse aqui, uma divergencia
 *      nao teria lado certo: o manifesto diria uma versao, a imagem rodaria
 *      outra, e so a imagem julga.
 *
 * O resultado util para o resto da #306 esta em {@see resolvedPackagesFor()}:
 * dado um conjunto de linguagens homologadas, ele devolve a lista de pacotes
 * JA COM O PINO -- que e o argumento de `apk add` de uma imagem sob medida.
 *
 * ## O que este passo NAO faz
 *
 * Nao gera imagem, nao cria perfil, nao muda Dockerfile nenhum. Os tres
 * seguem escritos a mao; o que muda e que agora divergir custa uma falha de
 * teste em vez de custar uma prova.
 */
final class ToolchainManifest
{
    /**
     * De onde sai cada programa que o catalogo chama.
     *
     * A chave e o que aparece nos comandos do catalogo: ou o nome do
     * executavel (inclusive por caminho absoluto, como os dois JDKs), ou o
     * caminho de um script de `{judge_runtime}` -- porque um wrapper esconde
     * o toolchain que ele chama, e o que esta escondido tambem tem de estar
     * declarado (o `cs_dotnet` nao menciona `dotnet` em comando nenhum: quem
     * chama e o `compile.sh`).
     *
     * @return array<string, list<ToolchainRequirement>>
     */
    public static function provenance(): array
    {
        return [
            // ---------------------------------------------------------
            // C, C++ e os front-ends do mesmo GCC
            // ---------------------------------------------------------
            'gcc' => [ToolchainRequirement::apk('gcc')],
            'g++' => [ToolchainRequirement::apk('g++')],
            'clang' => [ToolchainRequirement::apk('clang22')],
            'clang++' => [ToolchainRequirement::apk('clang22')],
            'gfortran' => [ToolchainRequirement::apk('gfortran')],
            'gnatmake' => [ToolchainRequirement::apk('gcc-gnat')],

            // ---------------------------------------------------------
            // JVM -- cada JDK no seu prefixo, e o `java` sem caminho, que
            // resolve para o `default-jvm` (hoje o 21) e e o que o `kt` usa
            // para rodar o jar que o kotlinc produz.
            // ---------------------------------------------------------
            '/usr/lib/jvm/java-21-openjdk/bin/javac' => [ToolchainRequirement::apk('openjdk21-jdk')],
            '/usr/lib/jvm/java-21-openjdk/bin/java' => [ToolchainRequirement::apk('openjdk21-jdk')],
            '/usr/lib/jvm/java-25-openjdk/bin/javac' => [ToolchainRequirement::apk('openjdk25-jdk')],
            '/usr/lib/jvm/java-25-openjdk/bin/java' => [ToolchainRequirement::apk('openjdk25-jdk')],
            'java' => [ToolchainRequirement::apk('openjdk21-jdk', 'o `default-jvm` do Alpine')],

            // ---------------------------------------------------------
            // Interpretados do Alpine
            // ---------------------------------------------------------
            'python3' => [ToolchainRequirement::apk('python3')],
            'node' => [ToolchainRequirement::apk('nodejs')],
            'ruby' => [ToolchainRequirement::apk('ruby')],
            'perl' => [ToolchainRequirement::apk('perl')],
            'lua5.4' => [ToolchainRequirement::apk('lua5.4')],
            'luac5.4' => [ToolchainRequirement::apk('lua5.4')],
            'gawk' => [ToolchainRequirement::apk('gawk')],
            'tclsh' => [ToolchainRequirement::apk('tcl')],
            'Rscript' => [ToolchainRequirement::apk('R')],
            'sbcl' => [ToolchainRequirement::apk('sbcl')],
            'clisp' => [ToolchainRequirement::apk('clisp')],
            'guile' => [ToolchainRequirement::apk('guile')],
            'guild' => [ToolchainRequirement::apk('guile', 'o compilador do proprio Guile')],
            'racket' => [ToolchainRequirement::apk('racket')],

            // ---------------------------------------------------------
            // BEAM -- declarada mesmo com `erl` e `ex` DESLIGADAS (#339)
            // ---------------------------------------------------------
            // Este arquivo responde "de onde sai este programa", e nao "esta
            // linguagem esta ligada": quem responde a segunda e o `is_active`
            // do catalogo, e o teste de paridade so percorre as ligadas. As
            // tres imagens instalam `elixir` e `erlang27` hoje (+86 MiB por
            // imagem, medido no comentario do Dockerfile.judge), entao a
            // procedencia existe de fato -- omiti-la era o unico lugar em que
            // a desativacao da #339 tinha resvalado para dentro do manifesto.
            //
            // A diferenca e pratica, e foi medida antes de escrever isto: sem
            // estas tres linhas, religar `erl`/`ex` reprovava tres casos da
            // paridade dizendo "o manifesto nao exige nada para esta
            // linguagem, o que so pode ser engano" e "declare a procedencia".
            // Vermelho que manda a proxima pessoa ACRESCENTAR estas linhas e
            // seguir reativando -- ou seja, um guard que aponta para o reparo
            // errado. Quem tem de reprovar ali, com o motivo certo, e
            // tests/Unit/Judge/CatalogoBeamDesativadaTest.php.
            'erlc' => [ToolchainRequirement::apk('erlang27')],
            'erl' => [ToolchainRequirement::apk('erlang27')],
            'elixir' => [ToolchainRequirement::apk('elixir')],

            // ---------------------------------------------------------
            // Compilados do Alpine
            // ---------------------------------------------------------
            'rustc' => [ToolchainRequirement::apk('rust')],
            'go' => [ToolchainRequirement::apk('go')],
            'ghc' => [ToolchainRequirement::apk('ghc')],
            'ocamlopt' => [ToolchainRequirement::apk('ocaml')],
            'ldc2' => [ToolchainRequirement::apk('ldc')],
            'nim' => [ToolchainRequirement::apk('nim')],
            'zig' => [ToolchainRequirement::apk('zig')],
            'crystal' => [ToolchainRequirement::apk('crystal')],
            'cobc' => [ToolchainRequirement::apk('gnucobol')],

            // ---------------------------------------------------------
            // Shell e utilitarios que sao linguagem do catalogo
            // ---------------------------------------------------------
            // `bash` nao e so a linguagem `sh`: e tambem o interpretador dos
            // scripts de `{judge_runtime}` e dos scripts de problema que o
            // AutoJudgeService executa. O Alpine nao o traz por padrao.
            'bash' => [ToolchainRequirement::apk('bash')],
            // `sed` e o do busybox, que ja vem na imagem base. Fica
            // declarado justamente para dizer que nao ha nada a instalar --
            // sem isto, "o catalogo cita um programa que o manifesto nao
            // conhece" seria indistinguivel de um esquecimento.
            'sed' => [ToolchainRequirement::baseImage('php:8.3-', 'o `sed` do busybox')],
            'php' => [ToolchainRequirement::baseImage('php:8.3-', 'o proprio interpretador da imagem')],

            // ---------------------------------------------------------
            // npm
            // ---------------------------------------------------------
            // `npx tsc` so funciona porque o `typescript` esta instalado
            // globalmente: o `npx` vem do pacote `npm`, e o compilador vem
            // do pacote global fixado por ARG. As duas exigencias sao
            // diferentes e as duas precisam estar escritas, senao tirar o
            // `npm install -g typescript` passaria despercebido.
            'npx' => [
                ToolchainRequirement::apk('npm'),
                ToolchainRequirement::npm('typescript'),
            ],

            // ---------------------------------------------------------
            // Baixados e fixados por sha256
            // ---------------------------------------------------------
            'kotlinc' => [
                ToolchainRequirement::download('KOTLIN'),
                ToolchainRequirement::invoker('kotlinc'),
                ToolchainRequirement::apk('openjdk21-jdk', 'o kotlinc roda no default-jvm'),
            ],
            'scalac' => [
                ToolchainRequirement::download('SCALA'),
                ToolchainRequirement::invoker('scalac'),
            ],
            'scala' => [
                ToolchainRequirement::download('SCALA'),
                ToolchainRequirement::invoker('scala'),
            ],
            'groovyc' => [
                ToolchainRequirement::download('GROOVY'),
                ToolchainRequirement::invoker('groovyc'),
            ],
            'groovy' => [
                ToolchainRequirement::download('GROOVY'),
                ToolchainRequirement::invoker('groovy'),
            ],
            'dart' => [
                ToolchainRequirement::download('DART'),
                ToolchainRequirement::invoker('dart'),
                ToolchainRequirement::apk('gcompat', 'o SDK oficial e glibc; o shim foi medido rodando'),
            ],
            'fpc' => [
                ToolchainRequirement::download('FPC'),
                ToolchainRequirement::invoker('fpc'),
            ],

            // ---------------------------------------------------------
            // Construidos num estagio e copiados
            // ---------------------------------------------------------
            'swipl' => [
                ToolchainRequirement::stage('swipl-builder'),
                ToolchainRequirement::invoker('swipl'),
                // Medido com `ldd /opt/swipl/bin/swipl` (ver Dockerfile.judge).
                ToolchainRequirement::apk('gmp', 'aritmetica de precisao arbitraria do Prolog'),
                ToolchainRequirement::apk('ncurses-libs'),
                ToolchainRequirement::apk('zlib'),
            ],
            'gplc' => [
                ToolchainRequirement::stage('gprolog-builder'),
                ToolchainRequirement::invoker('gplc'),
            ],
            // Issue #347 -- o `compile_command` liga o envolucro do juiz, que
            // e o que transforma erro de execucao em codigo de saida != 0 em
            // vez de `WA` mudo. Ele vem do repositorio junto com o codigo da
            // aplicacao, e nao de instalacao nenhuma.
            'gprolog/envolucro.pro' => [
                ToolchainRequirement::repoFile('resources/judge-runtime/gprolog/envolucro.pro'),
            ],
            'scratch-run' => [
                ToolchainRequirement::stage('scratch-run-builder'),
                ToolchainRequirement::invoker('scratch-run'),
                ToolchainRequirement::apk('nodejs', 'o invocador e um `node /opt/scratch-run/index.js`'),
            ],
            // As duas classes sao fonte DESTE repositorio, compiladas no
            // estagio: `ExecutaPortugol` (#301) executa, `VerificaPortugol`
            // (#269) so analisa. Nenhuma das duas e o Console.
            'portugol-studio' => [
                ToolchainRequirement::stage('portugol-studio-builder'),
                ToolchainRequirement::repoFile('docker/judge/portugol/ExecutaPortugol.java'),
                ToolchainRequirement::invoker('portugol-studio'),
                ToolchainRequirement::apk('openjdk21-jdk', 'o Portugol Studio roda na JVM, e compila para Java em tempo de execucao'),
            ],
            'portugol-studio-check' => [
                ToolchainRequirement::stage('portugol-studio-builder'),
                ToolchainRequirement::repoFile('docker/judge/portugol/VerificaPortugol.java'),
                ToolchainRequirement::invoker('portugol-studio-check'),
                ToolchainRequirement::apk('openjdk21-jdk'),
            ],

            // ---------------------------------------------------------
            // Invocadores versionados neste repositorio
            //
            // Nao sao baixados nem instalados: sao arquivos de
            // docker/judge/bin, copiados inteiros para /usr/local/bin. As
            // duas exigencias andam juntas de proposito -- apagar o arquivo
            // e apagar a linha de COPY quebram a mesma linguagem, e as duas
            // passariam batido num Dockerfile lido por cima.
            // ---------------------------------------------------------
            'clojure-check' => [
                ToolchainRequirement::repoFile('docker/judge/bin/clojure-check'),
                ToolchainRequirement::invoker('clojure-check'),
                ToolchainRequirement::apk('clojure'),
                ToolchainRequirement::apk('openjdk21-jdk'),
            ],
            'clojure-run' => [
                ToolchainRequirement::repoFile('docker/judge/bin/clojure-run'),
                ToolchainRequirement::invoker('clojure-run'),
                ToolchainRequirement::apk('clojure'),
                ToolchainRequirement::apk('openjdk21-jdk'),
            ],
            'racket-check' => [
                ToolchainRequirement::repoFile('docker/judge/bin/racket-check'),
                ToolchainRequirement::invoker('racket-check'),
                ToolchainRequirement::apk('racket'),
            ],
            'tcl-check' => [
                ToolchainRequirement::repoFile('docker/judge/bin/tcl-check'),
                ToolchainRequirement::invoker('tcl-check'),
                ToolchainRequirement::apk('tcl'),
            ],

            // ---------------------------------------------------------
            // Scripts de {judge_runtime}
            //
            // O comando do catalogo chama `bash {judge_runtime}/...`, entao o
            // executavel visivel e o `bash`. O toolchain de verdade esta
            // DENTRO do script, e e aqui que ele fica declarado.
            // ---------------------------------------------------------
            'csharp/compile.sh' => [
                ToolchainRequirement::repoFile('resources/judge-runtime/csharp/compile.sh'),
                ToolchainRequirement::apk('dotnet8-sdk'),
            ],
            'csharp/run.sh' => [
                ToolchainRequirement::repoFile('resources/judge-runtime/csharp/run.sh'),
                ToolchainRequirement::apk('dotnet8-sdk'),
            ],
            'node/run.sh' => [
                ToolchainRequirement::repoFile('resources/judge-runtime/node/run.sh'),
                ToolchainRequirement::apk('nodejs'),
            ],
            'python' => [
                ToolchainRequirement::repoFile('resources/judge-runtime/python/sitecustomize.py'),
                ToolchainRequirement::apk('python3'),
            ],

            // Issue #296 -- G-Portugol. O comando do catalogo chama `bash
            // {judge_runtime}/gportugol/compile.sh`, e o script chama DOIS
            // programas: o `gpt`, que vem do estagio, e o `gcc`, que compila
            // o C que ele traduz. Os dois ficam declarados aqui porque
            // nenhum dos dois aparece no comando do catalogo -- e um
            // Dockerfile que copiasse o `gpt` sem o `gcc` compilaria a
            // imagem e reprovaria toda submissao.
            'gportugol/compile.sh' => [
                ToolchainRequirement::repoFile('resources/judge-runtime/gportugol/compile.sh'),
                ToolchainRequirement::stage('gportugol-builder'),
                ToolchainRequirement::invoker('gpt'),
                ToolchainRequirement::apk('gcc', 'a segunda etapa: o C traduzido pelo `gpt -t` e compilado pelo gcc da imagem'),
            ],
        ];
    }

    /**
     * O que nao e de uma linguagem so, mas esta no caminho de toda execucao
     * julgada.
     *
     * `bubblewrap` e o confinamento (docs/specs/49-judge-isolation.md): sem
     * ele o AutoJudgeService se recusa a rodar codigo submetido, entao uma
     * imagem que o perdesse nao julgaria nada -- e uma que trocasse de versao
     * mudaria o que o codigo submetido alcanca.
     *
     * `libstdc++` e a biblioteca de execucao em C++ dos toolchains
     * construidos em C++ (o LDC e o Crystal arrastam LLVM). Os tres
     * Dockerfiles a instalam, em grupos diferentes e sem atribui-la a uma
     * linguagem; fica aqui pelo mesmo motivo.
     *
     * @return list<ToolchainRequirement>
     */
    public static function shared(): array
    {
        return [
            ToolchainRequirement::apk('bubblewrap', 'o confinamento do juiz'),
            ToolchainRequirement::apk('libstdc++'),
        ];
    }

    /**
     * As chaves do manifesto que um comando usa: o executavel com que ele
     * comeca, mais os scripts de `{judge_runtime}` que ele cita.
     *
     * A regra do executavel e UMA so, e mora aqui: pula prefixos
     * `VAR=valor`, e um token que CONTEM `{` em qualquer posicao encerra a
     * leitura, para que `./{executable}` (o binario que a propria
     * compilacao acabou de produzir) nao seja lido como um programa.
     *
     * Ela nasceu como uma "diferenca deliberada" em relacao ao roteamento
     * por capacidade (MachineCapabilities), que descartava apenas o token
     * COMECADO por `{`. A #354 mostrou que a diferenca nao era uma escolha:
     * era o defeito. `./{executable}` comeca com ponto, entao a sonda ia
     * procurar o molde, falhava, e as 22 linguagens compiladas sumiam da
     * lista declarada -- um judgehost remoto recusava C, C++, Rust e Go.
     * Desde entao o `MachineCapabilities` delega para ca, porque duas
     * copias da regra foi exatamente como elas passaram a discordar.
     *
     * @return list<string>
     */
    public static function dependenciesOf(string $command): array
    {
        $keys = [];

        $executable = self::executableOf($command);

        if ($executable !== null) {
            $keys[] = $executable;
        }

        if (preg_match_all('#\{judge_runtime\}/([A-Za-z0-9_./-]+)#', $command, $matches) > 0) {
            foreach ($matches[1] as $path) {
                $keys[] = $path;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * O programa que um comando executa, ou null se ele nao chama nenhum
     * programa instalado (o `./{executable}` dos compilados).
     */
    public static function executableOf(string $command): ?string
    {
        foreach (preg_split('/\s+/', trim($command)) ?: [] as $token) {
            if ($token === '' || str_contains($token, '=')) {
                continue;
            }

            if (str_contains($token, '{')) {
                return null;
            }

            return $token;
        }

        return null;
    }

    /**
     * Tudo que uma linguagem do catalogo exige, sem repeticao.
     *
     * @param  array<string, mixed>  $language  uma entrada de Language::getDefaultLanguages()
     * @return list<ToolchainRequirement>
     */
    public static function requirementsFor(array $language): array
    {
        $provenance = self::provenance();
        $requirements = [];

        foreach (self::keysUsedBy($language) as $key) {
            foreach ($provenance[$key] ?? [] as $requirement) {
                $requirements[$requirement->key()] = $requirement;
            }
        }

        return array_values($requirements);
    }

    /**
     * As chaves do manifesto que uma entrada do catalogo usa.
     *
     * @param  array<string, mixed>  $language
     * @return list<string>
     */
    public static function keysUsedBy(array $language): array
    {
        $keys = [];

        foreach (['compile_command', 'run_command'] as $field) {
            $command = (string) ($language[$field] ?? '');

            if ($command === '') {
                continue;
            }

            foreach (self::dependenciesOf($command) as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * As linguagens que o catalogo liga por padrao.
     *
     * @return list<array<string, mixed>>
     */
    public static function activeLanguages(): array
    {
        $active = [];

        foreach (Language::getDefaultLanguages() as $language) {
            if (($language['is_active'] ?? false) === true) {
                $active[] = $language;
            }
        }

        return $active;
    }

    /**
     * As chaves que o catalogo ativo usa e o manifesto nao conhece.
     *
     * E a lista que tem de estar vazia: uma linguagem ligada cujo programa
     * ninguem declarou de onde vem e precisamente a #302 -- funciona na
     * maquina de quem a ligou, e some na imagem que alguem esqueceu.
     *
     * @return array<string, list<string>> chave => extensoes que a usam
     */
    public static function uncoveredKeys(): array
    {
        $provenance = self::provenance();
        $missing = [];

        foreach (self::activeLanguages() as $language) {
            foreach (self::keysUsedBy($language) as $key) {
                if (! array_key_exists($key, $provenance)) {
                    $missing[$key][] = (string) ($language['extension'] ?? '?');
                }
            }
        }

        return $missing;
    }

    /**
     * Os pacotes apk de um conjunto de linguagens, JA COM O PINO lido do
     * Dockerfile -- ou seja, a linha de `apk add` de uma imagem sob medida.
     *
     * E o gancho para os passos seguintes da #306 (perfis e geracao por
     * prova). Aqui ele nao gera nada: serve para o teste de paridade poder
     * afirmar que todo pacote de toolchain esta fixado, e para deixar
     * demonstrado que o manifesto e legivel por codigo e nao so por gente.
     *
     * @param  iterable<array<string, mixed>>  $languages
     * @return list<string> pacotes no formato `nome=pino`, em ordem
     */
    public static function resolvedPackagesFor(iterable $languages, DockerfileToolchain $image): array
    {
        $packages = [];

        $collect = function (ToolchainRequirement $requirement) use (&$packages, $image): void {
            if ($requirement->kind !== ToolchainRequirement::APK) {
                return;
            }

            $pin = $image->pinFor($requirement);
            $packages[$requirement->target] = $pin === null
                ? $requirement->target
                : $requirement->target.'='.$pin;
        };

        foreach (self::shared() as $requirement) {
            $collect($requirement);
        }

        foreach ($languages as $language) {
            foreach (self::requirementsFor($language) as $requirement) {
                $collect($requirement);
            }
        }

        ksort($packages);

        return array_values($packages);
    }
}
