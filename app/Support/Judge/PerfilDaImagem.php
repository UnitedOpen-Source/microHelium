<?php

namespace App\Support\Judge;

use RuntimeException;

/**
 * Issue #306, passo 3 -- de qual perfil ESTA imagem foi construida, e
 * portanto o que ela promete julgar.
 *
 * O `Dockerfile.judge` recebe `--build-arg JUDGE_PROFILE=<perfil>` e grava o
 * nome em `ENV JUDGE_PROFILE`. E da imagem que a maquina parceira fala, e nao
 * do repositorio: por isso o nome vem do ambiente do processo.
 *
 * ## Por que `getenv()`, e nao `config()`
 *
 * Os data providers do PHPUnit sao estaticos e rodam ANTES de a aplicacao
 * subir -- e as suites E2E que percorrem "as linguagens desta imagem" sao
 * justamente data providers. `config()` ali nao existe. O ambiente existe em
 * qualquer ponto do processo, e e a mesma fonte que o Laravel leria.
 *
 * ## Ausente e `completo`
 *
 * Maquina que nao diz nada e a de sempre: toda imagem anterior a esta mudanca,
 * a imagem da aplicacao, o ambiente de desenvolvimento. `completo` e o
 * catalogo ativo inteiro, entao para elas nada muda.
 *
 * Nome DESCONHECIDO, ao contrario, e erro alto. Um perfil com erro de
 * digitacao que virasse `completo` em silencio faria uma imagem enxuta se
 * declarar capaz de tudo -- o defeito que esta classe existe para impedir,
 * ao contrario.
 */
final class PerfilDaImagem
{
    public const PADRAO = 'completo';

    public static function nome(): string
    {
        $nome = $_SERVER['JUDGE_PROFILE'] ?? $_ENV['JUDGE_PROFILE'] ?? getenv('JUDGE_PROFILE');

        return is_string($nome) && trim($nome) !== '' ? trim($nome) : self::PADRAO;
    }

    public static function perfil(): ToolchainProfile
    {
        $perfil = ToolchainProfile::named(self::nome());

        if ($perfil === null) {
            throw new RuntimeException(sprintf(
                'JUDGE_PROFILE=%s nao e um perfil conhecido (%s). A imagem foi construida com um nome que o codigo nao sabe resolver.',
                self::nome(),
                implode(', ', array_keys(ToolchainProfile::all())),
            ));
        }

        return $perfil;
    }

    /**
     * As extensoes que esta imagem promete julgar.
     *
     * @return list<string>
     */
    public static function prometidas(): array
    {
        return self::perfil()->runs();
    }

    public static function promete(string $extension): bool
    {
        return in_array($extension, self::prometidas(), true);
    }

    /**
     * Se esta imagem pode DECLARAR a extensao dada como capacidade.
     *
     * `completo` nao restringe nada, e isso e deliberado, nao atalho: o
     * catalogo padrao nao e o universo. O admin cria linguagem propria por
     * contest (`languages` tem `contest_id`), e uma maquina completa que as
     * recusasse por nao estarem em `getDefaultLanguages()` pararia de julgar
     * o que sempre julgou. Imagem enxuta, ao contrario, declara so o que o
     * perfil promete -- inclusive recusando linguagem customizada cujo
     * binario por acaso exista nela, porque isso ninguem testou ali.
     */
    public static function permite(string $extension): bool
    {
        return self::nome() === self::PADRAO || self::promete($extension);
    }
}
