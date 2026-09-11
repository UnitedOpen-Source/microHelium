<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #46 -- membership with an "editor" role, proposed as the mechanism
 * that lets a non-admin edit their own organization's problem-bank items
 * (docs/specs/46-bank-ownership.md). `role` is a plain string rather than an
 * enum so a future role (e.g. an org admin) doesn't require a migration.
 * `users.user_id` is an unsigned INT primary key (see
 * 2017_06_17_011612_create_users_table.php), not the bigint default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->unsignedInteger('user_id');
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->string('role', 30)->default('editor');
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_memberships');
    }
};
