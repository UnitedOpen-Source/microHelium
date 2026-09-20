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
     * Os pacotes que o estagio final manda instalar: nome => pino (ou null
     * quando a linha nao fixa versao).
     *
     * @return array<string, string|null>
     */
    public function apkPackages(): array
    {
        $packages = [];
        $inside = false;

        foreach (explode("\n", $this->finalStage) as $line) {
            $line = trim($line);

            if (! $inside) {
                if (preg_match('/^RUN\s+apk\s+add\b/', $line) !== 1) {
                    continue;
                }

                $inside = true;
                $line = (string) preg_replace('/^RUN\s+apk\s+add\b/', '', $line);
            }

            // Um comentario no meio da lista nao interrompe a continuacao de
            // linha; ele so nao contribui pacote nenhum.
            if (str_starts_with($line, '#')) {
                continue;
            }

            $continues = str_ends_with($line, '\\');
            $line = trim(rtrim($line, '\\'));

            foreach (preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                // Opcoes (`--no-cache`) e variaveis de build ($PHPIZE_DEPS,
                // que e uma lista inteira resolvida pela imagem base).
                if (str_starts_with($token, '-') || str_starts_with($token, '$')) {
                    continue;
                }

                [$name, $pin] = array_pad(explode('=', $token, 2), 2, null);
                $packages[$name] = $pin;
            }

            if (! $continues) {
                $inside = false;
            }
        }

        return $packages;
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
