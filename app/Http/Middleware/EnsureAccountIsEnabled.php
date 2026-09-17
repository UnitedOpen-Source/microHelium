<?php

namespace App\Http\Middleware;

use App\Models\ContestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue #277, partes 2 e 3 -- `is_enabled` revalidado a cada requisicao.
 *
 * A parte 1 (o login web) foi fechada no PR #280. Sozinha ela responde
 * "quem pode ENTRAR", e a issue faz mais duas perguntas:
 *
 *   2. desabilitar derruba quem ja esta dentro?
 *   3. desabilitar invalida o token ja emitido?
 *
 * Ate aqui a resposta era nao para as duas. `Backend\UserController::update()`
 * grava `is_enabled = false` e mais nada: a sessao aberta continuava valendo
 * ate o proximo logout, e o token continuava valendo ate expirar. Desabilitar
 * uma conta durante um incidente e a acao obvia de quem opera, e ela nao
 * derrubava nada.
 *
 * O `Api\TokenController` ja sabia disso, e o docblock dele diz por que a
 * checagem na emissao nao basta: "a token issued inside the lab also keeps
 * working outside it, so the check is at issue time and again is not enough
 * on its own". Este middleware e o "again".
 *
 * ## Por que revalidar, e nao apagar os tokens
 *
 * Apagar os tokens no momento de desabilitar tambem derrubaria o acesso, e
 * foi descartado: reabilitar a conta depois deixaria os clientes de API
 * quebrados em silencio, sem que ninguem tivesse pedido isso. Revalidar e
 * imediato, cobre sessao e token pelo mesmo caminho, e e reversivel -- que e
 * o que "desabilitar temporariamente" quer dizer.
 *
 * Rotacao de credencial e outra pergunta: se a conta foi COMPROMETIDA, o
 * token precisa morrer mesmo depois de reabilitar. Isso e revogacao
 * explicita, nao efeito colateral de desabilitar, e fica de fora de proposito.
 *
 * ## Onde roda
 *
 * Nos tres grupos que autenticam gente: `web` (sessao), `api` (Sanctum) e o
 * grupo da Contest API (#195), onde o token decide se o consumidor ve
 * veredito retido -- o mesmo vazamento que a #274 fechou para o anonimo
 * valeria para um juiz desabilitado.
 *
 * Nao roda no grupo do judgehost nem no do webcast: aqueles nao autenticam
 * User nenhum, tem guard proprio, e `is_enabled` nao existe la.
 */
class EnsureAccountIsEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        $user = $guard !== null ? $request->user($guard) : $request->user();

        // Requisicao anonima, ou conta em ordem: nada a fazer. A maioria
        // esmagadora das requisicoes sai por aqui.
        if ($user === null || $user->is_enabled) {
            return $next($request);
        }

        $this->record($user);

        // O caminho do token: nao ha sessao para invalidar, e 403 e a mesma
        // resposta que `Api\TokenController` da na emissao. Manter as duas
        // iguais importa: um cliente que trate "403 Conta desabilitada" ja
        // trata os dois momentos.
        if (! $request->hasSession()) {
            return response()->json(['message' => 'Conta desabilitada.'], 403);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Conta desabilitada.'], 403);
        }

        return redirect()->route('login')->withErrors([
            'email' => 'Esta conta esta desabilitada. Procure a organizacao do evento.',
        ]);
    }

    /**
     * "Quem continuou tentando com conta desabilitada, e quando" e pergunta
     * que se faz depois de um incidente -- mas o caminho do token repete a
     * cada requisicao, e um cliente em laco encheria `contest_logs` de
     * linhas identicas ate o disco acabar.
     *
     * Uma linha por conta por hora responde a pergunta e nao vira inundacao.
     * `Cache::add()` porque e atomico: dois processos simultaneos gravam uma
     * linha, nao duas.
     */
    private function record($user): void
    {
        // `contest_logs.contest_id` e obrigatorio, e a tela que le esses
        // registros filtra por prova: uma linha sem contest existiria e
        // nenhuma tela a mostraria. Uma conta sem prova -- admin, ou quem so
        // usa o Treino Livre -- simplesmente nao gera registro aqui.
        //
        // Isto nao e detalhe de estilo: passar null a `ContestLog::warning()`
        // e TypeError, ou seja, 500 no lugar da recusa. Era exatamente o que
        // acontecia no login do PR #280, e a suite de Treino Livre pegou.
        $contestId = $user->contest_id ?? $user->site?->contest_id;

        if ($contestId === null) {
            return;
        }

        if (! Cache::add('conta-desabilitada-log:'.$user->user_id, true, 3600)) {
            return;
        }

        ContestLog::warning(
            (int) $contestId,
            'Acesso encerrado: conta desabilitada',
            ['user_id' => $user->user_id]
        );
    }
}
