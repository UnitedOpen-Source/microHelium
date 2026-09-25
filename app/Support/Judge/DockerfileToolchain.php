<?php

namespace App\Support\Judge;

/**
 * Issue #306 -- o que um Dockerfile REALMENTE instala, lido do arquivo.
 *
 * Este e o lado que impede o manifesto de virar ficcao. Ele nao constroi
 * imagem: le o Dockerfile e responde, por exigencia do manifesto, "esta
 * imagem satisfaz isto?" e "com que pino?".
 *
 * A escolha por analise estatica e a mesma que a #302 sugeriu: construir as
 * tres imagens no CI custa dezenas de minutos e so responde depois que
 * alguem se lembrou de rodar; ler os tres arquivos custa milissegundos e
 * roda na suite de sempre. O preco e conhecido -- isto confere o que o
 * Dockerfile MANDA instalar, nao o que o `apk` de fato resolveu. Quem
 * confere o toolchain instalado de verdade ja existe e roda dentro da imagem
 * (tests/E2E/ToolchainVersionsMatchCatalogTest.php,
 * tests/E2E/MultiLanguageJudgingTest.php); o buraco entre os dois era
 * justamente "a imagem X nem recebeu ordem de instalar isto", que e a #302.
 *
 * ## So o estagio final conta
 *
 * Um `apk add gcc` num estagio de build nao poe gcc na imagem final. Por
 * isso tudo que fala de conteudo da imagem (pacotes, invocadores) e lido a
 * partir do ULTIMO `FROM` do arquivo. Os ARGs, esses, sao lidos do arquivo
 * inteiro: e nos estagios de build que moram as versoes e os sha256 do
 * SWI-Prolog, do GNU Prolog, do scratch-run e do Portugol Studio.
 */
final class DockerfileToolchain
{
    /**
     * O comeco de uma linha de instalacao por `apk`.
     *
     * Issue #306, passo 3: no `Dockerfile.judge` a lista passa pelo
     * `docker/judge/perfil/perfil apk-add`, que tira dela os pacotes que o
     * perfil da imagem nao usa. A LISTA continua a mesma, com os mesmos
     * pinos -- e e ela que este parser le. Ler so `apk add` faria o
     * `Dockerfile.judge` parecer uma imagem sem toolchain nenhum.
     */
    private const APK_ADD = '/^RUN\s+(?:apk\s+add|\S*\/perfil\s+apk-add)\b/';

    private function __construct(
        public readonly string $name,
        private readonly string $source,
        private readonly string $finalStage,
        private readonly string $repoRoot,
    ) {}

    public static function fromFile(string $path, string $repoRoot): self
    {
        $source = (string) file_get_contents($path);

        return new self(basename($path), $source, self::lastStageOf($source), rtrim($repoRoot, '/'));
    }

    /**
     * Opcoes do `apk add` que consomem o token seguinte como argumento.
     */
    private const APK_OPTIONS_WITH_ARGUMENT = ['--virtual', '-t', '--repository', '-X', '--root', '-p', '--arch'];

    /**
     * Os pacotes que o estagio final manda instalar e que FICAM na imagem:
     * nome => pino (ou null quando a linha nao fixa versao).
     *
     * Issue #391 -- tres regras que a lista ingenua (todo token depois de
     * `apk add`, ate o fim da instrucao) nao respeitava, e que so nao faziam
     * diferenca porque nenhuma linha de `apk` do repositorio tinha `&&` nem
     * `--virtual`:
     *
     * 1. o comando `apk add` termina no primeiro separador de shell (`&&`,
     *    `||`, `;`, `|`). O que vem depois e OUTRO comando -- lido como
     *    lista, `docker-php-ext-install` e `pdo_pgsql` viravam "pacotes", e o
     *    workflow `pinos-alpine.yml`, que simula esta lista, reprovava;
     * 2. `--virtual NOME` (e as outras opcoes com argumento) consome o token
     *    seguinte: o nome do grupo nao e pacote;
     * 3. um grupo `--virtual` removido com `apk del` no MESMO `RUN` e
     *    dependencia de construcao -- nao chega a imagem final, entao nao e
     *    pacote DELA. E exatamente o que a #391 faz com o `postgresql-dev`.
     *
     * @return array<string, string|null>
     */
    public function apkPackages(): array
    {
        $packages = [];

        foreach ($this->runInstructions() as $run) {
            if (preg_match(self::APK_ADD, $run) !== 1) {
                continue;
            }

            $tokens = preg_split('/\s+/', trim((string) preg_replace(self::APK_ADD, '', $run)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $found = [];
            $virtual = null;

            for ($i = 0; $i < count($tokens); $i++) {
                $token = $tokens[$i];

                if (in_array($token, ['&&', '||', ';', '|'], true)) {
                    break;
                }

                // `pacote;` fecha o comando com o proprio token.
                $ends = str_ends_with($token, ';');
                $token = rtrim($token, ';');

                if (in_array($token, self::APK_OPTIONS_WITH_ARGUMENT, true)) {
                    $argument = $tokens[++$i] ?? null;

                    if (in_array($token, ['--virtual', '-t'], true)) {
                        $virtual = $argument;
                    }

                    continue;
                }

                // Opcoes sem argumento (`--no-cache`) e variaveis de build
                // ($PHPIZE_DEPS, que e uma lista inteira resolvida pela
                // imagem base).
                if ($token !== '' && ! str_starts_with($token, '-') && ! str_starts_with($token, '$')) {
                    [$name, $pin] = array_pad(explode('=', $token, 2), 2, null);
                    $found[$name] = $pin;
                }

                if ($ends) {
                    break;
                }
            }

            if ($virtual !== null && preg_match('/\bapk\s+del\b[^&;|]*\s'.preg_quote($virtual, '/').'(?=\s|$|&|;|\|)/', $run) === 1) {
                continue;
            }

            $packages = array_merge($packages, $found);
        }

        return $packages;
    }

    /**
     * As instrucoes RUN do estagio final, uma por string, com as
     * continuacoes de linha juntadas e as linhas de comentario descartadas --
     * o que o proprio Docker faz antes de executar.
     *
     * @return list<string>
     */
    private function runInstructions(): array
    {
        $runs = [];
        $current = null;

        foreach (explode("\n", $this->finalStage) as $line) {
            $line = trim($line);

            // Um comentario no meio da lista nao interrompe a continuacao de
            // linha; ele so nao contribui nada.
            if (str_starts_with($line, '#')) {
                continue;
            }

            if ($current === null) {
                if (! str_starts_with($line, 'RUN ') && $line !== 'RUN') {
                    continue;
                }

                $current = '';
            }

            $continues = str_ends_with($line, '\\');
            $current .= ' '.trim(rtrim($line, '\\'));

            if (! $continues) {
                $runs[] = trim((string) preg_replace('/\s+/', ' ', $current));
                $current = null;
            }
        }

        return $runs;
    }

    /**
     * Pacotes globais do npm: nome => versao literal (resolvendo o ARG).
     *
     * @return array<string, string|null>
     */
    public function npmPackages(): array
    {
        $packages = [];
        $args = $this->args();

        if (preg_match_all('/npm\s+install\s+-g\s+"?([A-Za-z0-9@\/_.-]+)@([^"\s]+)"?/', $this->finalStage, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $version = $match[2];

                if (preg_match('/^\$\{([A-Za-z0-9_]+)\}$/', $version, $arg) === 1) {
                    $version = $args[$arg[1]] ?? null;
                }

                $packages[$match[1]] = $version;
            }
        }

        return $packages;
    }

    /**
     * Os programas que a imagem final passa a ter em /usr/local/bin.
     *
     * Duas procedencias: os que uma linha do proprio Dockerfile CRIA ali
     * (um `printf > ...`, um `ln -s`, um `install -m 755`), e os que chegam
     * inteiros pelo `COPY docker/judge/bin/ /usr/local/bin/` -- estes sao
     * lidos do diretorio no disco, e nao de uma lista escrita a mao, para
     * que acrescentar um invocador novo nao exija editar nada aqui.
     *
     * Um `chmod` sozinho nao conta: ele nao poe programa nenhum na imagem.
     *
     * @return list<string>
     */
    public function invokers(): array
    {
        $invokers = [];

        $creators = '#(?:>\s*|ln\s+-s\s+\S+\s+|install\s+-m\s*[0-7]+\s+\S+\s+|-o\s+)/usr/local/bin/([A-Za-z0-9_.+-]+)#';

        if (preg_match_all($creators, $this->finalStage, $matches) > 0) {
            $invokers = $matches[1];
        }

        if (preg_match('#COPY\s+(?:--\S+\s+)*docker/judge/bin/?\s+/usr/local/bin/?#', $this->finalStage) === 1) {
            foreach (glob($this->repoRoot.'/docker/judge/bin/*') ?: [] as $file) {
                $invokers[] = basename($file);
            }
        }

        return array_values(array_unique($invokers));
    }

    /**
     * A linha que CRIA um invocador, normalizada.
     *
     * Existir com o nome certo nao basta: dois `/usr/local/bin/portugol-studio`
     * podem chamar programas diferentes. Foi o que aconteceu no #351, que
     * trocou o invocador do Portugol Studio no Dockerfile.judge (do `Console`
     * com `-no-wait`, que engole erro de execucao, para o `ExecutaPortugol`)
     * e deixou as duas imagens da aplicacao com o invocador antigo -- a
     * mesma forma da #302, uma camada mais fundo.
     *
     * Compara-se so a linha da criacao, e nao o RUN inteiro: as verificacoes
     * de fumaca ao redor dela sao diferentes de proposito entre as imagens.
     * Quando o redirecionamento esta numa linha propria (`> /usr/local/...`),
     * a linha anterior entra junto, porque e ela que tem o comando.
     */
    public function invokerRecipe(string $name): ?string
    {
        $lines = explode("\n", $this->finalStage);
        $creators = '#(?:>\s*|ln\s+-s\s+\S+\s+|install\s+-m\s*[0-7]+\s+\S+\s+|-o\s+)/usr/local/bin/'
            .preg_quote($name, '#').'(?![A-Za-z0-9_.+-])#';

        foreach ($lines as $index => $line) {
            if (preg_match($creators, $line) !== 1) {
                continue;
            }

            $recipe = $line;

            if (str_starts_with(trim($line), '>') && $index > 0) {
                $recipe = $lines[$index - 1].' '.$line;
            }

            return self::normalize($recipe);
        }

        // Os invocadores versionados no repositorio nao tem receita no
        // Dockerfile: eles chegam inteiros pelo COPY do diretorio, e o
        // conteudo deles e o mesmo arquivo para as tres imagens.
        return in_array($name, $this->invokers(), true) ? 'COPY docker/judge/bin/' : null;
    }

    private static function normalize(string $line): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace('\\'."\n", ' ', $line)), " \t\\");
    }

    /**
     * Os estagios que existem E sao copiados para a imagem final.
     *
     * @return list<string>
     */
    public function stagesCopiedIn(): array
    {
        preg_match_all('/^FROM\s+\S+\s+AS\s+(\S+)/mi', $this->source, $declared);
        preg_match_all('/COPY\s+(?:--\S+\s+)*--from=([A-Za-z0-9_.-]+)/', $this->finalStage, $copied);

        $declaredNames = array_map('strtolower', $declared[1]);

        return array_values(array_intersect($declaredNames, array_map('strtolower', $copied[1])));
    }

    /**
     * Os ARGs do arquivo inteiro -- inclusive os dos estagios de build, onde
     * moram as versoes e os sha256 do que e compilado do fonte.
     *
     * @return array<string, string>
     */
    public function args(): array
    {
        preg_match_all('/^\s*ARG\s+([A-Za-z0-9_]+)=(\S+)/m', $this->source, $matches, PREG_SET_ORDER);

        $args = [];

        foreach ($matches as $match) {
            $args[$match[1]] = $match[2];
        }

        return $args;
    }

    /**
     * A imagem de que o estagio final parte.
     */
    public function baseImage(): string
    {
        return preg_match('/^FROM\s+(\S+)/m', $this->finalStage, $match) === 1 ? $match[1] : '';
    }

    public function satisfies(ToolchainRequirement $requirement): bool
    {
        return match ($requirement->kind) {
            ToolchainRequirement::APK => array_key_exists($requirement->target, $this->apkPackages()),
            ToolchainRequirement::NPM => array_key_exists($requirement->target, $this->npmPackages()),
            ToolchainRequirement::DOWNLOAD => $this->downloadIsPinned($requirement->target),
            ToolchainRequirement::STAGE => in_array(strtolower($requirement->target), $this->stagesCopiedIn(), true),
            ToolchainRequirement::INVOKER => in_array($requirement->target, $this->invokers(), true),
            ToolchainRequirement::REPO_FILE => file_exists($this->repoRoot.'/'.$requirement->target),
            ToolchainRequirement::BASE_IMAGE => str_starts_with($this->baseImage(), $requirement->target),
            default => false,
        };
    }

    /**
     * O que tem de ser igual nos tres arquivos para a exigencia ser a mesma
     * exigencia -- o pino do pacote, a versao do npm, o par versao+hash do
     * download. Null para o que nao carrega versao (um estagio, um arquivo
     * do repositorio), e para o pacote que a linha nao fixou.
     */
    public function pinFor(ToolchainRequirement $requirement): ?string
    {
        return match ($requirement->kind) {
            ToolchainRequirement::APK => $this->apkPackages()[$requirement->target] ?? null,
            ToolchainRequirement::NPM => $this->npmPackages()[$requirement->target] ?? null,
            ToolchainRequirement::DOWNLOAD => $this->downloadPins($requirement->target),
            default => null,
        };
    }

    /**
     * Um download so esta fixado com VERSAO e HASH: a versao sozinha diz
     * qual release se pediu, o hash e o que prova que foi aquela que chegou.
     */
    private function downloadIsPinned(string $prefix): bool
    {
        $args = $this->args();

        if (! array_key_exists($prefix.'_VERSION', $args)) {
            return false;
        }

        foreach (array_keys($args) as $name) {
            if (str_starts_with($name, $prefix.'_SHA256')) {
                return true;
            }
        }

        return false;
    }

    private function downloadPins(string $prefix): ?string
    {
        $pins = [];

        foreach ($this->args() as $name => $value) {
            if (str_starts_with($name, $prefix.'_')) {
                $pins[] = $name.'='.$value;
            }
        }

        if ($pins === []) {
            return null;
        }

        sort($pins);

        return implode(' ', $pins);
    }

    /**
     * Do ultimo `FROM` ate o fim: o que sobra na imagem que se publica.
     */
    private static function lastStageOf(string $source): string
    {
        $lines = explode("\n", $source);
        $start = 0;

        foreach ($lines as $index => $line) {
            if (preg_match('/^FROM\s/', $line) === 1) {
                $start = $index;
            }
        }

        return implode("\n", array_slice($lines, $start));
    }
}
