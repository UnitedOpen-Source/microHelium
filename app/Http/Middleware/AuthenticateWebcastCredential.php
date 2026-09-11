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
 * a linear hash_equals() scan of every row). No separate hash_equals()
 * re-check is done after the lookup: a row can only be returned by
 * `WHERE token_hash = $hash`, so `$credential->token_hash === $hash`
 * always holds for any matched row -- comparing them again is dead code,
 * not a real timing-safety measure (the DB index equality lookup itself
 * isn't a PHP-level string-comparison timing side-channel the way
 * comparing two variables with `===` would be).
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
        // Eager-load contest here: ScoreboardController::show() (the only
        // route this guard protects) always needs it, and this endpoint is
        // the one meant to be polled repeatedly -- without this, every
        // request pays for a second, lazily-loaded query.
        $credential = WebcastCredential::with('contest')->where('token_hash', $hash)->first();

        if (! $credential || $credential->isRevoked() || $credential->isExpired()) {
            abort(401, 'Credencial de transmissao invalida ou ausente.');
        }

        $credential->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('webcastCredential', $credential);

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        // The auth-scheme name ("Bearer") is case-insensitive per RFC
        // 7235 -- only the scheme prefix is compared case-insensitively
        // here; the token itself is taken from the original, unmodified
        // remainder of the header.
        if (str_starts_with(strtolower($header), 'bearer ')) {
            return trim(substr($header, 7));
        }

        return null;
    }
}
