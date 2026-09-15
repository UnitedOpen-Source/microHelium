<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #193 -- hold a problem's judging without taking it off the contest.
 *
 * The scenario, which is not hypothetical: mid-contest the jury finds that
 * the expected output of problem C is wrong. Teams keep submitting C. Every
 * submission collects a WRONG ANSWER caused by the jury's own defect, and
 * every one of those costs the team twenty penalty minutes.
 *
 * Until now the only levers were to deactivate the problem -- which takes
 * the statement away from the teams -- or to let it keep judging wrongly
 * and rejudge afterwards, which #192 says is one run at a time.
 *
 * The CCS requirements name this exactly: "The CCS must support pausing the
 * judging of submissions for a specific problem while still allowing teams
 * to submit to that problem." The submission is accepted, sits pending, and
 * the team sees "being evaluated" -- which is true.
 *
 * Separate from `is_active`/deactivating on purpose: deactivating removes
 * the problem from the contest, pausing holds only the verdict. They are
 * different acts and the interface has to be able to say which.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('problems', function (Blueprint $table) {
            $table->timestamp('judging_paused_at')->nullable()->after('auto_judge');
            $table->unsignedInteger('judging_paused_by')->nullable()->after('judging_paused_at');

            $table->foreign('judging_paused_by')->references('user_id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('problems', function (Blueprint $table) {
            $table->dropForeign(['judging_paused_by']);
            $table->dropColumn(['judging_paused_at', 'judging_paused_by']);
        });
    }
};
