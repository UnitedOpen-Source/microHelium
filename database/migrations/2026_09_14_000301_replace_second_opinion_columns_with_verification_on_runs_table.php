<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #138 -- drop BOCA's symmetric second opinion, add DOMjudge's
 * verification gate.
 *
 * What is being dropped. `answer1_id`, `judge1_id`, `answer2_id` and
 * `judge2_id` came from BOCA's `runanswer1`/`runanswer2` pair
 * (src/frun.php, DBUpdateRunC()), where two humans answer the same run
 * independently and the published verdict is written -- and the team
 * notified -- only when the two agree, or when the chief judge rules on the
 * disagreement (src/judge/runchief.php, runeditchief.php). Measured, not
 * assumed: a grep over the whole repository finds these four columns
 * mentioned in exactly two places, this table's CREATE and Run::$fillable.
 * No reader, no writer; the BOCA importer and exporter do not touch them
 * either, so nothing round-trips through them. They promised a feature the
 * code never had.
 *
 * What replaces them, and why not a like-for-like implementation. This
 * platform is autojudge-first. BOCA's shape was designed when every run was
 * judged by hand; a deterministic autojudge does not disagree with itself,
 * so asking a second person to independently re-answer every run buys
 * nothing and spends the contest's turnaround time. The three cases that do
 * still want a second pair of eyes -- a hand-judged run, a verdict a team
 * contests, a problem with a special checker (#120) -- are all "one more
 * person looks before the team sees it", which is a verification gate, not
 * symmetric double judging.
 *
 * So: DOMjudge's three fields on `judging`, by their own descriptions --
 * `verified` ("Result verified by jury member?"), `jury_member` ("Name of
 * jury member who verified this") and `verify_comment` ("Optional additional
 * information provided by the verifier"). `verified_at` rather than a
 * boolean because when a verdict was released is the question an appeal
 * asks, and a timestamp answers both.
 *
 * ICPC rules mandate neither model. The Regional Rules say only that "each
 * run is judged as accepted or rejected, and the team is notified of the
 * results" and that "the judges are solely responsible for accepting or
 * rejecting submitted runs"; the Policies and Procedures give the Finals
 * Chief Judge a role that "supervises judging and resolves judging
 * exceptions" -- an escalation path, not a mandatory second signature on
 * every verdict. This is a product decision, and it is written down here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The constraints come off before the columns, on every driver.
        // MySQL refuses to drop a column a foreign key still names; SQLite
        // does too, in its own way -- it rebuilds the table on a dropColumn
        // and then fails validating the carried-over FK clause with
        // "unknown column \"answer1_id\" in foreign key definition", which
        // is what a first version of this migration hit in the suite.
        Schema::table('runs', function (Blueprint $table) {
            $table->dropForeign(['answer1_id']);
            $table->dropForeign(['judge1_id']);
            $table->dropForeign(['answer2_id']);
            $table->dropForeign(['judge2_id']);
        });

        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn(['answer1_id', 'judge1_id', 'answer2_id', 'judge2_id']);
        });

        Schema::table('runs', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('judge_site_id');
            $table->unsignedInteger('verified_by')->nullable()->after('verified_at');
            $table->text('verify_comment')->nullable()->after('verified_by');

            $table->foreign('verified_by')->references('user_id')->on('users')->nullOnDelete();

            // The gate's hot query is "judged runs of this contest that are
            // still withheld" -- the judge screen's pending-verification
            // list and the per-cell scoreboard recompute both ask it.
            $table->index(['contest_id', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropIndex(['contest_id', 'verified_at']);

            $table->dropForeign(['verified_by']);
        });

        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn(['verified_at', 'verified_by', 'verify_comment']);
        });

        // Restored in the exact shape 2025_11_25_000008_create_runs_table
        // gave them, foreign keys included, so a rollback lands on the
        // schema that migration would have produced.
        Schema::table('runs', function (Blueprint $table) {
            $table->foreignId('answer1_id')->nullable()->after('judge_site_id')->constrained('answers')->nullOnDelete();
            $table->unsignedInteger('judge1_id')->nullable()->after('answer1_id');
            $table->foreignId('answer2_id')->nullable()->after('judge1_id')->constrained('answers')->nullOnDelete();
            $table->unsignedInteger('judge2_id')->nullable()->after('answer2_id');

            $table->foreign('judge1_id')->references('user_id')->on('users')->nullOnDelete();
            $table->foreign('judge2_id')->references('user_id')->on('users')->nullOnDelete();
        });
    }
};
