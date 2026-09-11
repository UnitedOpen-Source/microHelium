<?php

namespace App\Services;

use App\Models\IdempotencyKey;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Shared Idempotency-Key handling for /api/frontend/* mutations, per
 * docs/specs/README.md's shared contract: "Backend deve persistir
 * resultado/ID por ator+rota+chave e rejeitar reutilizacao com payload
 * diferente."
 *
 * Usage: $guard->handle($request, fn () => [$status, $body, $storedBody]);
 * $storedBody is optional and, when given, is what gets persisted for
 * later replay instead of $body -- used by webcast credential issuance to
 * redact the one-time secret before it ever reaches this table (see
 * App\Http\Controllers\Frontend\WebcastController::storeCredential()).
 *
 * Requests without an Idempotency-Key header are run straight through
 * with no persistence at all -- idempotency is opt-in per the contract,
 * not mandatory for every mutating call.
 */
class IdempotencyGuard
{
    public function handle(Request $request, callable $callback): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if (! $key) {
            [$status, $body] = $this->normalize($callback());

            return response()->json($body, $status);
        }

        $actor = 'user:'.(auth()->id() ?? 'guest');
        $route = $request->method().' '.$request->path();
        $payloadHash = hash('sha256', json_encode($request->all(), JSON_UNESCAPED_UNICODE));

        $existing = IdempotencyKey::where('actor', $actor)
            ->where('route', $route)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing) {
            return $this->replay($existing, $payloadHash);
        }

        try {
            $record = IdempotencyKey::create([
                'actor' => $actor,
                'route' => $route,
                'idempotency_key' => $key,
                'payload_hash' => $payloadHash,
                'status' => 'in_progress',
            ]);
        } catch (QueryException $e) {
            // Two requests with the same key raced past the first() check
            // above and both tried to insert -- the loser hits the unique
            // constraint (idempotency_actor_route_key_unique). Rather than
            // let that surface as an unhandled 500, treat it exactly like
            // finding the row on the first lookup: replay/409 as
            // appropriate against whatever the winner ends up recording.
            $existing = IdempotencyKey::where('actor', $actor)
                ->where('route', $route)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing) {
                return $this->replay($existing, $payloadHash);
            }

            throw $e;
        }

        try {
            [$status, $body, $storedBody] = $this->normalize($callback());
        } catch (ValidationException|HttpResponseException $e) {
            // A clean, synchronous rejection (422 validation, or an
            // explicit abort()) never touched persistent state, so it is
            // not ambiguous -- free the key immediately so the client can
            // retry (with a corrected payload, or the same one) without
            // hitting the "ambiguous" 409 path below.
            $record->delete();
            throw $e;
        } catch (\Throwable $e) {
            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
                $record->delete();
                throw $e;
            }

            // Anything else may have partially committed side effects
            // before failing. Leave the record as in_progress so a retry
            // with the same key is treated as ambiguous (409) instead of
            // silently re-running -- see replay() below.
            throw $e;
        }

        $record->forceFill([
            'status' => 'completed',
            'response_status' => $status,
            'response_body' => Crypt::encryptString(json_encode($storedBody ?? $body, JSON_UNESCAPED_UNICODE)),
        ])->save();

        return response()->json($body, $status);
    }

    private function replay(IdempotencyKey $record, string $payloadHash): JsonResponse
    {
        if (! hash_equals($record->payload_hash, $payloadHash)) {
            abort(409, 'Esta Idempotency-Key ja foi usada com dados diferentes. Use uma chave nova.');
        }

        if ($record->status === 'in_progress') {
            abort(409, 'A operacao anterior com esta chave nao foi concluida com certeza. Verifique o estado atual antes de repetir, ou revogue/reemita.');
        }

        $body = json_decode(Crypt::decryptString($record->response_body), true);

        return response()->json($body, $record->response_status);
    }

    /**
     * @return array{0:int,1:array,2:?array}
     */
    private function normalize(array $result): array
    {
        return [$result[0], $result[1], $result[2] ?? null];
    }
}
