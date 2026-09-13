<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #53 -- one row per judge machine.
 *
 * At the DOMjudge rule of thumb of one judgehost per 10 to 20 teams, a
 * 1000-team contest wants 50 to 100 machines; their own World Finals of 140
 * teams ran on a laptop. One box does not cover the top of that range.
 *
 * The credential is per machine on purpose. DOMjudge uses one shared
 * password for every judgehost and takes the hostname from the request
 * body, so any holder of the secret can register as any host and have
 * another host's in-flight work given back. Here the identity comes from
 * the credential and the name is only a label.
 *
 * Only the sha256 of the token is stored. The token itself is shown once,
 * when the credential is created, and never again -- the same shape as
 * webcast_credentials (#44).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('judgehosts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('token_hash', 64)->unique();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_seen_at')->nullable();

            // users.user_id is an unsignedInteger; foreignId() here would be
            // a BIGINT and MySQL would reject the constraint with 3780.
            $table->unsignedInteger('created_by')->nullable();
            $table->foreign('created_by')->references('user_id')->on('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['enabled', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('judgehosts');
    }
};
