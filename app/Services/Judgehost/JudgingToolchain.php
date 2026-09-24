<?php

namespace App\Services\Judgehost;

use Throwable;

/**
 * Issue #392 -- o carimbo que cada julgamento leva: com qual versao do
 * toolchain e em qual perfil de imagem ele foi feito.
 *
 * O #373 (#303) ja sabia perguntar a versao a maquina, mas so no `register`,
 * e guardava a resposta no HOST -- que um re-registro sobrescreve. Numa
 * maratona a versao do compilador e parte do edital: um rejulgamento meses
 * depois, numa maquina reconstruida, pode dar outro veredito, e o registro
 * tem de estar no JULGAMENTO para se poder mostrar que deu (ou que nao deu).
 *
 * Um dono so, usado pelos dois caminhos que julgam: o agente do judgehost
 * remoto (que manda o carimbo junto com o veredito) e a fila local
 * (`AutoJudgeService::judge()` e o julgamento de sombra do rejulgamento).
 *
 * Tres regras, as mesmas do #303:
 *
 * 1. **Sonda, nao pino.** A versao vem de `MachineCapabilities::versionsOf()`
 *    na maquina que julga, e nao do Dockerfile.
 * 2. **Memoizada por processo.** A sonda de `kotlinc` sobe uma JVM; paga-se
 *    uma vez por processo, e nao por julgamento. Por isso este servico e
 *    singleton no container.
 * 3. **Nunca derruba julgamento.** Uma sonda que falha vira `null`, que e
 *    "nao disse" -- um veredito sem versao vale mais que nenhum veredito.
 */
class JudgingToolchain
{
    /**
     * Imagem que nao diz de qual perfil veio e a de sempre: o catalogo
     * inteiro (#306).
     */
    public const PERFIL_PADRAO = 'completo';

    public function __construct(private MachineCapabilities $machine) {}

    /**
     * De qual perfil esta imagem foi construida.
     *
     * Lido direto do ambiente, como a imagem o grava (`ENV JUDGE_PROFILE`).
     * Quando o #393 entrar, isto passa a delegar a
     * `App\Support\Judge\PerfilDaImagem::nome()`, que le a mesma variavel
     * com a mesma regra de ausencia.
     */
    public static function perfil(): string
    {
        $nome = getenv('JUDGE_PROFILE');

        return is_string($nome) && trim($nome) !== '' ? trim($nome) : self::PERFIL_PADRAO;
    }

    /**
     * A versao do toolchain desta extensao nesta maquina, ou null quando
     * nao ha receita para ela ou a sonda nao respondeu.
     */
    public function versaoDe(string $extension): ?string
    {
        if ($extension === '') {
            return null;
        }

        try {
            return $this->machine->versionsOf([$extension])[$extension] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * O que vai junto com o veredito.
     *
     * @return array{toolchain_version: string|null, toolchain_profile: string}
     */
    public function carimbo(string $extension): array
    {
        return [
            'toolchain_version' => $this->versaoDe($extension),
            'toolchain_profile' => self::perfil(),
        ];
    }
}
