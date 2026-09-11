<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #47 -- one-time activation tokens for managed accounts created
 * without a usable password (see App\Http\Controllers\AccountActivationController).
 * Only the SHA-256 hash of the token is ever stored; the raw token lives
 * only in the one-time `activation_url` returned to the admin at creation
 * time and in the URL the recipient visits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_activations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_activations');
    }
};
