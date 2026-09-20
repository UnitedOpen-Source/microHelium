<?php

namespace App\Support\Judge;

/**
 * Issue #306 -- uma exigencia de instalacao, dita de um jeito que da para
 * conferir sem construir imagem.
 *
 * Cada valor destes responde "de onde sai este programa dentro da imagem?".
 * Sao seis procedencias, e elas cobrem tudo que os tres Dockerfiles fazem
 * hoje:
 *
 *   - `apk`          -- pacote do Alpine (`apk add`);
 *   - `npm`          -- pacote global do npm (`npm install -g`);
 *   - `download`     -- artefato baixado, fixado por versao E por sha256;
 *   - `stage`        -- construido num estagio multi-stage e copiado;
 *   - `invoker`      -- um programa que passa a existir em /usr/local/bin;
 *   - `repoFile`     -- um arquivo DESTE repositorio (os scripts de
 *                       `{judge_runtime}` e os invocadores de
 *                       `docker/judge/bin`);
 *   - `baseImage`    -- ja vem da imagem base, e por isso nao se instala.
 *
 * A VERSAO nao mora aqui de proposito. Quem fixa `ghc=9.10.3-r2` e o
 * Dockerfile; este objeto diz apenas que `ghc` vem do pacote `ghc`. Repetir
 * o pino aqui criaria uma segunda verdade escrita a mao -- exatamente o
 * problema que a #302 mostrou -- e deixaria ambiguo qual das duas esta certa
 * quando divergissem. O pino e LIDO do Dockerfile por
 * {@see DockerfileToolchain::pinFor()}, e o teste de paridade exige que os
 * tres digam o mesmo.
 */
final class ToolchainRequirement
{
    public const APK = 'apk';

    public const NPM = 'npm';

    public const DOWNLOAD = 'download';

    public const STAGE = 'stage';

    public const INVOKER = 'invoker';

    public const REPO_FILE = 'repo_file';

    public const BASE_IMAGE = 'base_image';

    private function __construct(
        public readonly string $kind,
        public readonly string $target,
        public readonly string $why,
    ) {}

    /**
     * Um pacote do Alpine. O pino vem do Dockerfile, nao daqui.
     */
    public static function apk(string $package, string $why = ''): self
    {
        return new self(self::APK, $package, $why);
    }

    /**
     * Um pacote global do npm, instalado com versao fixada num ARG.
     */
    public static function npm(string $package, string $why = ''): self
    {
        return new self(self::NPM, $package, $why);
    }

    /**
     * Um artefato baixado: o alvo e o PREFIXO dos ARGs que o fixam, de modo
     * que `download('KOTLIN')` exige `ARG KOTLIN_VERSION` e pelo menos um
     * `ARG KOTLIN_SHA256...` (algumas ferramentas publicam um hash por
     * arquitetura, dai o sufixo opcional).
     */
    public static function download(string $argPrefix, string $why = ''): self
    {
        return new self(self::DOWNLOAD, $argPrefix, $why);
    }

    /**
     * Um estagio de build: tem de existir (`FROM ... AS <nome>`) e tem de
     * ser copiado para a imagem final (`COPY --from=<nome>`). Um estagio que
     * ninguem copia e peso de build sem efeito nenhum na imagem.
     */
    public static function stage(string $name, string $why = ''): self
    {
        return new self(self::STAGE, $name, $why);
    }

    /**
     * Um programa que a imagem final passa a ter em /usr/local/bin, seja
     * porque um `printf`/`ln -s`/`install` o cria ali, seja porque veio no
     * `COPY docker/judge/bin/ /usr/local/bin/`.
     */
    public static function invoker(string $name, string $why = ''): self
    {
        return new self(self::INVOKER, $name, $why);
    }

    /**
     * Um arquivo deste repositorio, conferido no disco. Os comandos do
     * catalogo chamam varios deles por `{judge_runtime}/...`, e a imagem os
     * leva junto com o codigo da aplicacao -- se o arquivo sumir, nenhum
     * Dockerfile muda e a linguagem para de compilar.
     */
    public static function repoFile(string $path, string $why = ''): self
    {
        return new self(self::REPO_FILE, $path, $why);
    }

    /**
     * Vem da imagem base. O alvo e o prefixo que a base tem de ter.
     */
    public static function baseImage(string $prefix, string $why = ''): self
    {
        return new self(self::BASE_IMAGE, $prefix, $why);
    }

    /**
     * A exigencia em uma linha, para a mensagem de falha do teste.
     */
    public function describe(): string
    {
        $text = match ($this->kind) {
            self::APK => "pacote apk `{$this->target}`",
            self::NPM => "pacote npm global `{$this->target}`",
            self::DOWNLOAD => "download fixado por ARG {$this->target}_VERSION + {$this->target}_SHA256",
            self::STAGE => "estagio de build `{$this->target}` copiado para a imagem final",
            self::INVOKER => "/usr/local/bin/{$this->target}",
            self::REPO_FILE => "arquivo do repositorio `{$this->target}`",
            self::BASE_IMAGE => "imagem base `{$this->target}`",
            default => "{$this->kind} `{$this->target}`",
        };

        return $this->why === '' ? $text : $text.' ('.$this->why.')';
    }

    /**
     * Chave estavel, para de-duplicar exigencias que varias linguagens
     * compartilham.
     */
    public function key(): string
    {
        return $this->kind.':'.$this->target;
    }
}
