<?php

namespace App\Support;

use App\Models\IdempotencyKey;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Shared Idempotency-Key mechanism required by every mutating
 * /api/frontend/* endpoint (docs/specs/README.md: "Backend deve persistir
 * resultado/ID por ator+rota+chave e rejeitar reutilização com payload
 * diferente"). Introduced for issue #47's managed-accounts create endpoint;
 * reuse `handle()` for any future /api/frontend/* POST/PATCH/DELETE.
 */
class IdempotencyStore
{
    /**
     * Runs $callback (which must return a JsonResponse) under the request's
     * `Idempotency-Key` header, scoped to the current actor + $route.
     *
     * The (user_id, route, idempotency_key) slot is CLAIMED via an INSERT
     * before $callback runs, not after -- the DB's unique index on those
     * three columns is the actual concurrency gate, not the SELECT used to
     * short-circuit the common (non-concurrent) case. Two truly concurrent
     * requests with the same key+payload (a double-click, a client retry
     * racing the original response) can both see "no existing row" from
     * that SELECT, but only one of their claim INSERTs can succeed; the
     * loser resolves against the winner's row instead of also running the
     * mutating callback body.
     *
     * - No header: 400 (this app's mutating /api/frontend/* actions always
     *   send one -- see resources/js/features/useFeature.js `act()`).
     * - Same key + same payload, already completed: replays the stored
     *   response without re-running $callback -- this is what makes a
     *   retry after a client timeout safe even though the first attempt
     *   already changed the database (e.g. created a user); re-running
     *   validation against post-creation state would incorrectly reject
     *   the retry (e.g. "username already taken" against the account IT
     *   created).
     * - Same key + same payload, still in flight (another request holds
     *   the claim and hasn't finished): 409 -- Idempotency-Key means "safe
     *   to retry this exact request," not "block until the original
     *   finishes."
     * - Same key + different payload: 409 (reused key, not a plain retry).
     * - $callback throwing (most commonly a ValidationException) releases
     *   the claim instead of leaving a permanently "pending" row, so the
     *   client can correct the payload and retry under the same key.
     */
    public static function handle(Request $request, string $route, callable $callback): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if (! $key) {
            abort(400, 'O cabecalho Idempotency-Key e obrigatorio para esta operacao.');
        }

        $userId = $request->user()->user_id;
        $payloadHash = hash('sha256', json_encode($request->all()));

        $existing = self::lookup($userId, $route, $key);

        if ($existing === null) {
            try {
                IdempotencyKey::create([
                    'user_id' => $userId,
                    'route' => $route,
                    'idempotency_key' => $key,
                    'payload_hash' => $payloadHash,
                    'response_status' => null,
                    'response_body' => null,
                ]);

                // We won the claim -- we are the only request allowed to
                // run $callback for this key right now.
                return self::runClaimed($callback, $userId, $route, $key);
            } catch (QueryException $e) {
                if (! self::isUniqueConstraintViolation($e)) {
                    throw $e;
                }

                // Lost the claim race: someone else's INSERT committed
                // between our SELECT and ours. Fall through and resolve
                // against their row below instead of running $callback.
                $existing = self::lookup($userId, $route, $key);
            }
        }

        if ($existing === null) {
            // Only reachable if the row we lost the race for was deleted
            // again (e.g. the winner's callback threw) before this re-read
            // -- fail safely rather than treat that as a fresh claim.
            abort(409, 'Nao foi possivel confirmar o estado desta operacao. Tente novamente.');
        }

        if (! hash_equals($existing->payload_hash, $payloadHash)) {
            abort(409, 'Esta chave de idempotencia ja foi usada com dados diferentes.');
        }

        if ($existing->response_status === null) {
            abort(409, 'Esta operacao ja esta em andamento. Aguarde a resposta original antes de repetir.');
        }

        return response()->json($existing->response_body, $existing->response_status);
    }

    /**
     * Runs $callback for a claim this process just won, then records the
     * result on that same row (or releases the claim entirely if
     * $callback throws, most commonly a ValidationException -- see the
     * class docblock).
     */
    private static function runClaimed(callable $callback, int|string $userId, string $route, string $key): JsonResponse
    {
        try {
            $response = $callback();
        } catch (Throwable $e) {
            self::rows($userId, $route, $key)->delete();
            throw $e;
        }

        self::rows($userId, $route, $key)->update([
            'response_status' => $response->getStatusCode(),
            'response_body' => $response->getData(true),
        ]);

        return $response;
    }

    private static function lookup(int|string $userId, string $route, string $key): ?IdempotencyKey
    {
        return self::rows($userId, $route, $key)->first();
    }

    private static function rows(int|string $userId, string $route, string $key)
    {
        return IdempotencyKey::query()
            ->where('user_id', $userId)
            ->where('route', $route)
            ->where('idempotency_key', $key);
    }

    /**
     * True only for a violation of THIS table's one unique index
     * (user_id, route, idempotency_key) -- not e.g. an unrelated NOT NULL
     * or foreign-key failure on the same insert, which should propagate
     * as a real error instead of being silently treated as "someone else
     * already claimed this key."
     */
    private static function isUniqueConstraintViolation(QueryException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;

        // MySQL: driver error 1062 is specifically "Duplicate entry".
        if ($driverCode === 1062) {
            return true;
        }

        // SQLite (used by this app's test suite) has no single generic
        // "unique violation" driver code the way MySQL's 1062 does, but
        // its message for this failure mode is specific and stable.
        return str_contains(strtolower($e->getMessage()), 'unique constraint failed');
    }
}
