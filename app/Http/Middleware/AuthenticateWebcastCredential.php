<?php

namespace App\Http\Middleware;

use App\Models\WebcastCredential;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue #44's read-only webcast principal. Deliberately NOT a `user_type`
 * and NOT the web session/Sanctum guard -- this authenticates a single
 * bearer token against webcast_credentials and attaches the resolved
 * App\Models\WebcastCredential to the request. It never calls Auth::
 * login(), so a resolved credential carries no identity recognized by any
 * other guard/middleware in the app: hitting any other authenticated route
 * with this same header still 401s/redirects exactly as an anonymous
 * request would (see tests/Feature/WebcastCredentialAuthTest.php).
 *
 * Mechanism chosen (spec left this open: "forma de autenticacao no
 * consumidor... deve ser confirmada"): `Authorization: Bearer <token>`,
 * looked up by sha256(token) against the unique token_hash column (the
 * same approach Laravel Sanctum itself uses for personal access tokens --
 * hash the presented token and use an indexed equality lookup rather than
 * a linear hash_equals() scan of every row). hash_equals() is still used
 * below to compare the resolved row's hash against the computed one, as a
 * defensive constant-time check beyond the indexed lookup.
 *
 * Active, expired, and revoked credentials -- and a token that matches no
 * row at all -- all produce the exact same 401 response, so a client can
 * never distinguish "wrong token" from "your access ended" (spec: "GET com
 * segredo ativo, expirado ou revogado deve ser indistinguivel na mensagem
 * publica de negacao").
 */
class AuthenticateWebcastCredential
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->bearerToken($request);

        if (! $token) {
            abort(401, 'Credencial de transmissao invalida ou ausente.');
        }

        $hash = hash('sha256', $token);
        $credential = WebcastCredential::where('token_hash', $hash)->first();

        if (! $credential || ! hash_equals($credential->token_hash, $hash) || $credential->isRevoked() || $credential->isExpired()) {
            abort(401, 'Credencial de transmissao invalida ou ausente.');
        }

        $credential->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('webcastCredential', $credential);

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            return trim(substr($header, 7));
        }

        return null;
    }
}
