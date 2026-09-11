<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #46 -- audit trail for problem-bank ownership transfers: origin,
 * destination, actor and time ("Auditar transferências com origem/destino,
 * ator e tempo."). One row is written per PATCH that actually changes
 * `owning_org_id`; tag-only edits do not write here.
 *
 * Every foreign key here is nullable + nullOnDelete(): an audit trail must
 * outlive the things it references. If the bank item is later deleted
 * (ProblemBankController::destroy()), the actor's account is removed, or an
 * organization is removed, the transfer row survives with that column set
 * to null rather than the whole audit row disappearing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('problem_bank_ownership_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('problem_bank_id')->nullable()->constrained('problem_bank')->nullOnDelete();
            $table->foreignId('from_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('to_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->unsignedInteger('actor_user_id')->nullable();
            $table->foreign('actor_user_id')->references('user_id')->on('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problem_bank_ownership_transfers');
    }
};
