<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic Idempotency-Key store for /api/frontend/* mutations (see
 * docs/specs/README.md's shared contract and app/Services/IdempotencyGuard.
 * php). Keyed by actor+route+key so a retry with the same key+payload
 * replays the original response instead of repeating the side effect, and
 * the same key with a different payload is rejected with 409.
 *
 * response_body is Crypt-encrypted at rest (never plaintext) because some
 * callers -- notably webcast credential issuance -- may need to persist a
 * one-time secret in the replay record; storing it encrypted, rather than
 * not at all, is what lets a legitimate same-payload retry after a dropped
 * response still work for ordinary actions while keeping the raw secret
 * out of plaintext storage. See WebcastController::storeCredential() for
 * why credential creation specifically still redacts the secret before
 * ever reaching this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('actor');
            $table->string('route');
            $table->string('idempotency_key');
            $table->string('payload_hash', 64);
            $table->string('status', 20)->default('in_progress');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamps();

            $table->unique(['actor', 'route', 'idempotency_key'], 'idempotency_actor_route_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
