<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #46 -- proposed "owning organization" model for the problem bank
 * (docs/specs/46-bank-ownership.md). Organization creation/management is a
 * separate, not-yet-scoped phase; rows are seeded/administered outside the
 * UI for now. `archived_at` exists so an organization with historical bank
 * items can be archived instead of deleted (spec: "preferir arquivar
 * organização com histórico") even though no archive endpoint ships yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
