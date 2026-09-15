<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContestLog;
use Helium\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issue #159 -- how a client that is not a browser gets into the API.
 *
 * Until this existed there was no way at all: the Sanctum table was never
 * created (see the migration), and even with the table there was neither a
 * route nor an artisan command that would call createToken(). Every one of
 * the 60-odd routes in routes/api.php was therefore unreachable by any real
 * client, and the suite did not notice because Sanctum::actingAs() injects
 * the user into the guard without issuing a token at all.
 *
 * The shape is the one Laravel documents for "mobile apps, CLI tools and
 * third-party API consumers": credentials in, one plain-text token out,
 * shown once. It is also what DOMjudge's submit client does -- it
 * authenticates to the API with the user's own username and password rather
 * than with a token an organiser has to mint and hand over -- which matters
 * because a contest has a few hundred teams and a couple of staff, and any
 * scheme where staff must mint a credential per team does not survive
 * contact with the morning of the event.
 *
 * What is NOT the documented shape, and is here because this is a contest
 * system:
 *
 *  - the site IP restriction (#50) is enforced on this route too. The web
 *    login refuses a session to an account whose site pins an address
 *    range; without the same check here, /api/tokens would be the way
 *    around the lock -- log in from anywhere, get a token, use it from
 *    anywhere. A token issued inside the lab also keeps working outside it,
 *    so the check is at issue time and again is not enough on its own;
 *    it is the same guarantee the web login gives, not a stronger one.
 *  - a disabled account (#47's managed accounts start disabled) is refused.
 *    auth()->attempt() does not look at is_enabled, so the web login has
 *    the same hole; that one is #47's, not this route's, but this route is
 *    not going to be the second copy of it.
 *  - issuing is logged to the contest log, because "who took a token and
 *    from where" is exactly the question asked after an incident.
 */
class TokenController extends Controller
{
    /**
     * POST /api/tokens -- exchange credentials for a bearer token.
     *
     * Unauthenticated by definition, so it carries its own throttle
     * (routes/api.php) matching the web login's 5/minute.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Not `email`: BOCA-style accounts are known to their team by
            // username, the web form asks for an e-mail, and a CLI should
            // not care which one the person in front of it remembers.
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            // Informational, and the only handle the person has when they
            // later look at the list and decide which token to revoke.
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = $this->resolveUser($validated['login']);

        // One message and one status for "no such account" and "wrong
        // password" alike: distinguishing them turns this route into an
        // account-enumeration oracle for a contest's participant list.
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['Credenciais invalidas.'],
            ]);
        }

        if (! $user->is_enabled) {
            return response()->json([
                'message' => 'Conta desabilitada.',
            ], 403);
        }

        if ($user->site && ! $user->site->isIpAllowed($request->ip())) {
            $this->logForUser($user, 'warning', 'Emissao de token bloqueada: IP fora da rede configurada para o site', [
                'ip' => $request->ip(),
                'site_id' => $user->site_id,
            ]);

            return response()->json([
                'message' => 'Acesso bloqueado: fora da rede autorizada para o seu site.',
            ], 403);
        }

        $expiresAt = $this->expiryDeadline();

        // Abilities are deliberately '*'. routes/api.php authorizes by
        // user_type through the `role:` middleware (#134) and nothing reads
        // tokenCan(), so a narrower ability list here would be a label with
        // no gate behind it -- worse than none, because it reads like a
        // restriction. Scoped tokens are worth having; they need the routes
        // to check them first.
        $token = $user->createToken($validated['device_name'], ['*'], $expiresAt);

        $this->logForUser($user, 'info', 'Token de API emitido', [
            'device_name' => $validated['device_name'],
            'ip' => $request->ip(),
            'expires_at' => $expiresAt?->toIso8601String(),
        ]);

        return response()->json([
            // Shown exactly once. Sanctum stores only its sha256, so there
            // is no second chance to read it and no way for staff to
            // recover someone's token -- same contract as judgehost:create
            // (#112).
            'token' => $token->plainTextToken,
            'name' => $validated['device_name'],
            'abilities' => ['*'],
            'expires_at' => $expiresAt?->toIso8601String(),
            'user' => [
                'id' => $user->user_id,
                'username' => $user->username,
                'fullname' => $user->fullname,
                'user_type' => $user->user_type,
                'contest_id' => $user->contest_id,
            ],
        ], 201);
    }

    /**
     * GET /api/tokens -- the caller's own tokens, never the plain text.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->tokens()
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (PersonalAccessToken $token) => $this->describe($token, $request))
                ->all(),
        ]);
    }

    /**
     * DELETE /api/tokens/current -- log this client out.
     *
     * Registered before {token} in routes/api.php, and {token} is
     * constrained to digits, so "current" cannot be read as an id.
     */
    public function destroyCurrent(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        // A session-authenticated caller (the SPA) holds a TransientToken,
        // which has no row to delete. Nothing to do, and saying so beats a
        // 500 from calling delete() on it.
        if (! $token instanceof PersonalAccessToken) {
            return response()->json([
                'message' => 'Esta requisicao nao foi autenticada por token.',
            ], 400);
        }

        $token->delete();

        return response()->json(['message' => 'Token revogado.']);
    }

    /**
     * DELETE /api/tokens/{token} -- revoke one of the caller's own tokens.
     *
     * Scoped through the tokens() relation rather than looked up by id and
     * then checked: a find-then-compare is one forgotten line away from
     * letting anyone revoke anyone's token by guessing a small integer.
     */
    public function destroy(Request $request, int $token): JsonResponse
    {
        $deleted = $request->user()->tokens()->whereKey($token)->delete();

        if ($deleted === 0) {
            return response()->json(['message' => 'Token nao encontrado.'], 404);
        }

        return response()->json(['message' => 'Token revogado.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(PersonalAccessToken $token, Request $request): array
    {
        $current = $request->user()->currentAccessToken();

        return [
            'id' => $token->id,
            'name' => $token->name,
            'abilities' => $token->abilities,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
            'current' => $current instanceof PersonalAccessToken && $current->getKey() === $token->getKey(),
        ];
    }

    /**
     * A user by e-mail, or failing that by username.
     *
     * E-mail first on purpose: `username` is unique and freely chosen at
     * registration, so someone could take another account's e-mail address
     * as their username. Looking the e-mail up first means that string can
     * only ever reach the account that owns it, and the impostor's own row
     * is still only reachable with the impostor's own password.
     */
    private function resolveUser(string $login): ?User
    {
        return User::where('email', $login)->first()
            ?? User::where('username', $login)->first();
    }

    private function expiryDeadline(): ?Carbon
    {
        $minutes = config('sanctum.expiration');

        return $minutes === null ? null : now()->addMinutes((int) $minutes);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logForUser(User $user, string $type, string $message, array $context): void
    {
        $contestId = $user->contest_id ?? $user->site?->contest_id;

        // contest_id is not nullable on contest_logs, and an account that
        // belongs to no contest (a global admin) has nothing to attach the
        // line to. Dropping it beats failing the request over an audit row.
        if ($contestId === null) {
            return;
        }

        ContestLog::log(
            (int) $contestId,
            $type,
            $message,
            siteId: $user->site_id,
            userId: $user->user_id,
            context: $context,
        );
    }
}
