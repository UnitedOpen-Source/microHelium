<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #53 -- which machine is judging this run, and since when.
 *
 * `claimed_at` is the half DOMjudge does not have. Its give-back protocol
 * runs when a judgehost re-registers, which covers a host that comes back
 * and not one that dies -- DOMjudge#2476 is a World Finals judging that
 * stayed stuck and needed a manual rejudge. A lease the server can expire
 * on its own costs one column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->foreignId('judgehost_id')->nullable()->after('status')
                ->constrained('judgehosts')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable()->after('judgehost_id');

            // The query the lease reaper runs, and the one fetch-work runs.
            $table->index(['status', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropIndex(['status', 'claimed_at']);
            $table->dropForeign(['judgehost_id']);
            $table->dropColumn(['judgehost_id', 'claimed_at']);
        });
    }
};
