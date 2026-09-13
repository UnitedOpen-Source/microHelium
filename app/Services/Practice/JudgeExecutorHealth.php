<?php

namespace App\Services\Practice;

/**
 * Issue #43 -- the gate between the practice library and the judge
 * (docs/specs/43-practice.md): "Habilitar envio somente com executor
 * isolado e saudável conforme #49; can_submit=false e 503 em mutação
 * quando indisponível."
 *
 * Fails closed, deliberately, and in both directions: an executor that is
 * merely *present* is not enough, it has to be the isolated one issue #49
 * specifies. A deployment that has turned the sandbox off is not a
 * deployment practice submissions may run on -- the spec is explicit that
 * "falha de disponibilidade/configuração do isolamento deve falhar fechada,
 * sem fallback para execução no host", and that can_submit must not be
 * enabled before that requirement is validated.
 *
 * The reason code is internal; the message is what a browser may see. The
 * spec asks for exactly that split -- "pode adicionar executor_status e
 * reason_code internos, separados do texto público [...] health pode
 * informar indisponibilidade sem paths/tokens/detalhes de host" -- so no
 * binary path, host name or config key ever reaches the public string.
 */
class JudgeExecutorHealth
{
    public const REASON_JUDGE_DISABLED = 'judge_disabled';

    public const REASON_SANDBOX_NOT_CONFIGURED = 'sandbox_not_configured';

    public const REASON_SANDBOX_DISABLED = 'sandbox_disabled';

    public const REASON_SANDBOX_UNAVAILABLE = 'sandbox_unavailable';

    public function isAvailable(): bool
    {
        return $this->reasonCode() === null;
    }

    /**
     * Internal status. Never returned to an unauthenticated caller as-is.
     */
    public function reasonCode(): ?string
    {
        if (! config('autojudge.enabled', true)) {
            return self::REASON_JUDGE_DISABLED;
        }

        $useSandbox = config('autojudge.use_bwrap');

        if ($useSandbox === null) {
            // The isolation work of #49 is not present in this build at all.
            return self::REASON_SANDBOX_NOT_CONFIGURED;
        }

        if (! $useSandbox) {
            return self::REASON_SANDBOX_DISABLED;
        }

        $sandboxPath = (string) config('autojudge.bwrap_path', '');

        if ($sandboxPath === '' || ! @is_executable($sandboxPath)) {
            return self::REASON_SANDBOX_UNAVAILABLE;
        }

        return null;
    }

    /**
     * Safe to show a participant: says that training submissions are off
     * and that it is an operational matter, without describing the host.
     */
    public function publicReason(): ?string
    {
        if ($this->reasonCode() === null) {
            return null;
        }

        return 'O ambiente de julgamento isolado esta indisponivel no momento, '
            .'entao o envio de treino esta temporariamente desabilitado. '
            .'Seu codigo nao foi perdido: tente novamente mais tarde.';
    }
}
