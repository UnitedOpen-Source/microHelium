<?php

namespace App\Support;

use App\Models\IdempotencyKey;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared "Idempotency-Key" mechanics for /api/frontend/* mutating routes
 * (docs/specs/README.md): retrying the SAME key with the SAME payload
 * replays the saved response instead of re-running the handler; the SAME
 * key with a DIFFERENT payload is a 409. Scoped per actor + route so two
 * different admins (or two different endpoints) never collide on the same
 * client-generated UUID.
 *
 * Deliberately keyed on the full response (status + body) rather than only
 * on "did it succeed": a 409 domain conflict (e.g. "an equivalent check is
 * already running") is just as much a real, already-computed outcome as a
 * 202 -- replaying it verbatim on retry is more correct than re-deriving it
 * (and potentially getting a different answer, e.g. because the earlier
 * conflicting check finished in the meantime) a second time.
 *
 * Validation failures are intentionally NOT persisted here: they never
 * reach this far because $request->validate()/ValidationException thrown
 * inside the callback propagate out of idempotent() to Laravel's normal
 * exception handler. Since nothing was mutated, there is nothing to record
 * -- retrying the same (still invalid) payload will just fail validation
 * again, deterministically, without needing a stored replay.
 */
trait HandlesIdempotency
{
    protected function idempotent(Request $request, string $route, \Closure $callback): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if (!$key) {
            return $callback();
        }

        $userId = auth()->id();
        $payloadHash = hash('sha256', json_encode($request->all()));

        $existing = IdempotencyKey::query()
            ->where('user_id', $userId)
            ->where('route', $route)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing) {
            return $this->replayOrConflict($existing, $payloadHash);
        }

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
            // Lost a race against a concurrent request with the identical
            // key (unique index violation) -- defer to whichever one won
            // rather than double-processing or dropping this response.
            $existing = IdempotencyKey::query()
                ->where('user_id', $userId)
                ->where('route', $route)
                ->where('idempotency_key', $key)
                ->first();

            if (!$existing) {
                throw $e;
            }

            return $this->replayOrConflict($existing, $payloadHash);
        }

        return $response;
    }

    private function replayOrConflict(IdempotencyKey $existing, string $payloadHash): JsonResponse
    {
        if ($existing->payload_hash !== $payloadHash) {
            return response()->json([
                'message' => 'Esta chave de idempotência já foi usada com dados diferentes.',
            ], 409);
        }

        return response()->json($existing->response_body, $existing->response_status);
    }
}
