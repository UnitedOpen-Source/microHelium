<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #159 -- the table the whole authenticated API depends on.
 *
 * `laravel/sanctum` is in composer.json, `auth:sanctum` guards every route
 * in routes/api.php and Helium\User uses HasApiTokens -- but this table was
 * never created by anything, so `$user->createToken()` threw "no such table"
 * and no client could obtain a bearer token at all. Sanctum 4 does not
 * load its own migrations (there is no loadMigrationsFrom in its service
 * provider); `php artisan install:api` publishes this file, and that step
 * was never run here.
 *
 * Verified against a real `php artisan migrate --force`, not by reading:
 * before this file, `personal_access_tokens` was absent from the schema
 * while migrate exited 0.
 *
 * Kept identical to vendor/laravel/sanctum's own migration so that Sanctum
 * upgrades stay applicable; the only thing added is this explanation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
