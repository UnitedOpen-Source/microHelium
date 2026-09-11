<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #44 -- read-only webcast credentials, scoped to one contest, used
 * exclusively by the separate AuthenticateWebcastCredential guard (see
 * app/Http/Middleware/AuthenticateWebcastCredential.php). Never joined to
 * the `users` table and never grants a `user_type` -- this is a distinct,
 * much smaller principal than any admin/judge/team/staff/score/site/system
 * account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webcast_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained('contests')->cascadeOnDelete();
            $table->string('label', 80);
            // sha256 hex digest of the raw secret. The raw secret itself is
            // never persisted anywhere, plaintext or otherwise -- only
            // returned once, in the create response.
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamps();

            $table->index(['contest_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webcast_credentials');
    }
};
