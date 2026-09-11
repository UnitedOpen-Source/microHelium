<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #46 -- proposed nullable owning organization + optimistic-concurrency
 * version for the problem bank (docs/specs/46-bank-ownership.md).
 *
 * Backfill intentionally leaves `owning_org_id` null for every existing row
 * ("Backfill mantém null; nunca atribuir todos os legados ao primeiro
 * usuário") -- legacy items stay admin-only until an admin assigns them.
 *
 * `owning_org_id` uses `restrictOnDelete()` (not cascade/null) so an
 * organization with bank items can't be deleted out from under them; the
 * spec's proposed answer is to archive the organization instead
 * (`organizations.archived_at`), transferring/unassigning items explicitly
 * first.
 *
 * `version` is a simple monotonically-incrementing integer (not
 * `updated_at`) so two writes inside the same second can still be told
 * apart -- required for the "two concurrent edits, second gets 409" test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('problem_bank', function (Blueprint $table) {
            $table->foreignId('owning_org_id')->nullable()->after('id')
                ->constrained('organizations')->restrictOnDelete();
            $table->unsignedBigInteger('version')->default(1)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('problem_bank', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owning_org_id');
            $table->dropColumn('version');
        });
    }
};
