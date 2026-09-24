<?php

namespace Tests\Concerns;

/**
 * Issue #392 -- simular "esta imagem foi construida com o perfil X" num teste.
 *
 * `PerfilDaImagem::nome()` (#306) le `$_SERVER`, depois `$_ENV`, depois
 * `getenv()`. Um teste que so faz `putenv('JUDGE_PROFILE=maratona')` passa
 * numa maquina sem a variavel e reprova DENTRO da imagem do juiz, onde o
 * `ENV JUDGE_PROFILE=completo` do Dockerfile ja esta no `$_SERVER` desde o
 * comeco do processo e vence o `putenv`. Foi exatamente assim que o CI do #402
 * pegou quatro testes que passavam no Mac. Este trait define o perfil nas tres
 * fontes e devolve as tres ao estado original no `tearDown`.
 */
trait DefinePerfilDaImagem
{
    /** @var array{0: mixed, 1: mixed, 2: string|false}|null */
    private ?array $perfilOriginalDaImagem = null;

    protected function definirPerfilDaImagem(?string $perfil): void
    {
        $this->perfilOriginalDaImagem ??= [
            $_SERVER['JUDGE_PROFILE'] ?? null,
            $_ENV['JUDGE_PROFILE'] ?? null,
            getenv('JUDGE_PROFILE'),
        ];

        self::aplicarPerfil($perfil, $perfil, $perfil === null ? false : $perfil);
    }

    protected function restaurarPerfilDaImagem(): void
    {
        if ($this->perfilOriginalDaImagem === null) {
            return;
        }

        [$server, $env, $processo] = $this->perfilOriginalDaImagem;
        self::aplicarPerfil($server, $env, $processo);
        $this->perfilOriginalDaImagem = null;
    }

    private static function aplicarPerfil(mixed $server, mixed $env, string|false $processo): void
    {
        if ($server === null) {
            unset($_SERVER['JUDGE_PROFILE']);
        } else {
            $_SERVER['JUDGE_PROFILE'] = $server;
        }

        if ($env === null) {
            unset($_ENV['JUDGE_PROFILE']);
        } else {
            $_ENV['JUDGE_PROFILE'] = $env;
        }

        putenv($processo === false ? 'JUDGE_PROFILE' : 'JUDGE_PROFILE='.$processo);
    }
}
