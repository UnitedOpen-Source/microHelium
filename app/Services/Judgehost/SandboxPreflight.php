<?php

namespace App\Services\Judgehost;

/**
 * Issue #282 -- "esta máquina pode julgar?", respondido em microssegundos.
 *
 * A autoridade sobre isso continua sendo `judgehost:selftest`, que executa
 * oito casos de verdade dentro do sandbox e leva segundos. Este serviço é a
 * parte BARATA da mesma pergunta: três condições que não precisam executar
 * nada, e que quando falham fazem os oito casos falharem também.
 *
 * Existe porque o silêncio era o sintoma. Quem sobe `docker-compose.dev.yml`
 * recebe a aplicação inteira e nenhuma capacidade de julgar -- o compose de
 * dev não tem serviço de juiz, e o `queue` dele roda na imagem do app, como
 * root. Submeter não dava erro: dava nada. A conclusão natural de quem chega
 * no projeto é "está quebrado", e não "esta pilha não julga de propósito".
 *
 * E havia a leitura pior: alguém "faz funcionar" -- instala o bwrap, liga
 * `privileged` -- e acaba com um ambiente que executa código submetido sem
 * confinamento nenhum, achando que está confinando. Medido: nesse arranjo o
 * `judgehost:selftest` reprova `fork_bomb`, `network` e `secrets`, o último
 * com `/etc/shadow` legível de dentro do sandbox.
 *
 * Uma definição só, consumida por três lugares: o daemon (`autojudge:start`,
 * que recusa subir), o job da fila (`JudgeRunJob`, que recusa julgar e diz
 * por quê) e o próprio selftest, como seus primeiros casos. Duas definições
 * de "pode julgar" seria como uma delas fica para trás.
 */
class SandboxPreflight
{
    public const KEY_DISABLED = 'sandbox_enabled';

    public const KEY_BINARY = 'sandbox_binary';

    public const KEY_PRIVILEGE = 'sandbox_privilege';

    /**
     * O que impede esta máquina de julgar, ou null quando nada impede.
     *
     * @return array{key: string, reason: string}|null
     */
    public function blocker(): ?array
    {
        if (! config('autojudge.use_bwrap', true)) {
            return [
                'key' => self::KEY_DISABLED,
                'reason' => 'AUTOJUDGE_USE_BWRAP esta DESLIGADO: o julgamento executaria codigo submetido sem confinamento.',
            ];
        }

        $bwrap = (string) config('autojudge.bwrap_path', '/usr/bin/bwrap');

        if (! file_exists($bwrap) || ! is_executable($bwrap)) {
            return [
                'key' => self::KEY_BINARY,
                'reason' => 'O bubblewrap nao esta em '.$bwrap.': instale-o, ou use a imagem de juiz (Dockerfile.judge).',
            ];
        }

        // A condição que o compose de dev cria, e a que ninguém procura.
        //
        // `docker/judge/entrypoint.sh` roda como root de propósito -- delega
        // o subtree de cgroup v2 -- e então BAIXA para uid 1000 com
        // `setpriv` antes de chamar o daemon. O comentário dele diz por quê
        // (#86). O `queue` do compose de dev não faz nada disso e roda como
        // root, e sandbox de namespace de usuário rodando como root não
        // confina: foi assim que `/etc/shadow` ficou legível de dentro.
        if ($this->isRoot()) {
            return [
                'key' => self::KEY_PRIVILEGE,
                'reason' => 'O julgamento esta rodando como root (uid 0), e nesse caso o sandbox nao confina: use a imagem de juiz, que baixa para uid 1000.',
            ];
        }

        return null;
    }

    /**
     * Separado para o teste poder dobrar: `posix_getuid()` não é algo que um
     * teste possa mudar, e uma guarda que só o CI consegue exercitar é uma
     * guarda que ninguém verifica.
     */
    protected function isRoot(): bool
    {
        return function_exists('posix_getuid') && posix_getuid() === 0;
    }
}
