<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shared by any /api/frontend/* mutating endpoint (see
     * App\Support\HandlesIdempotency): docs/specs/README.md requires that
     * retrying the same Idempotency-Key with the same payload replays the
     * saved response, and the same key with a different payload gets a 409,
     * scoped per actor + route.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('route');
            $table->string('idempotency_key');
            $table->string('payload_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->timestamps();

            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'route', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
