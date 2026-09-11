<?php

namespace App\Support;

use App\Models\IdempotencyKey;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * - No header: 400 (this app's mutating /api/frontend/* actions always
     *   send one -- see resources/js/features/useFeature.js `act()`).
     * - Same key + same payload seen before: replays the stored response
     *   without re-running $callback at all -- this is what makes a retry
     *   after a client timeout safe even though the first attempt already
     *   changed the database (e.g. created a user); re-running validation
     *   against post-creation state would incorrectly reject the retry
     *   (e.g. "username already taken" against the account IT created).
     * - Same key + different payload: 409 (reused key, not a plain retry).
     * - Validation failures inside $callback are never persisted, so a
     *   client that fixes its payload and retries under the same key is not
     *   permanently blocked by a stored 422.
     */
    public static function handle(Request $request, string $route, callable $callback): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if (! $key) {
            abort(400, 'O cabecalho Idempotency-Key e obrigatorio para esta operacao.');
        }

        $userId = $request->user()->user_id;
        $payloadHash = hash('sha256', json_encode($request->all()));

        $existing = IdempotencyKey::query()
            ->where('user_id', $userId)
            ->where('route', $route)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing) {
            if (! hash_equals($existing->payload_hash, $payloadHash)) {
                abort(409, 'Esta chave de idempotencia ja foi usada com dados diferentes.');
            }

            return response()->json($existing->response_body, $existing->response_status);
        }

        // Validation exceptions propagate untouched -- not persisted, so
        // the client can correct the payload and retry under the same key.
        $response = $callback();

        try {
            IdempotencyKey::create([
                'user_id' => $userId,
                'route' => $route,
                'idempotency_key' => $key,
                'payload_hash' => $payloadHash,
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getData(true),
            ]);
        } catch (QueryException $e) {
            // Two concurrent identical requests both passed the lookup
            // above; the DB unique index (user_id, route, idempotency_key)
            // is the final guard against a double row. The first request to
            // reach here wins the cache; this one's own $response is still
            // correct to return (it operated on the same, now-created,
            // database state) -- so we simply do not overwrite the cache.
        }

        return $response;
    }
}
