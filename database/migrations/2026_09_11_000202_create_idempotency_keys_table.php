<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs App\Support\IdempotencyStore -- the shared Idempotency-Key mechanism
 * required by every mutating /api/frontend/* endpoint (docs/specs/README.md:
 * "Backend deve persistir resultado/ID por ator+rota+chave e rejeitar
 * reutilização com payload diferente"). Introduced by issue #47's
 * managed-accounts create endpoint; any later /api/frontend/* POST/PATCH/
 * DELETE can reuse the same table via IdempotencyStore::handle().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('route');
            $table->string('idempotency_key');
            $table->string('payload_hash');
            // Nullable: a row is inserted to atomically CLAIM the
            // (user_id, route, idempotency_key) slot before the mutating
            // callback runs (see IdempotencyStore::handle()), and only
            // gets its response_status/response_body once the callback
            // finishes -- a still-null response_status means "another
            // request is processing this key right now."
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'route', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
