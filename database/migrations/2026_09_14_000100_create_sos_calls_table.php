<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #139 -- BOCA's S.O.S. button (src/team/task.php).
 *
 * A table of its own rather than a row in `tasks`. `tasks` is the staff
 * *errand* queue: balloons to walk over (#87) and files to print (#94),
 * numbered per site and worked through at the pace of the contest. An
 * S.O.S. is not an errand -- it is "something is wrong with this team right
 * now" -- and it needs a state a person can move (open -> acknowledged ->
 * resolved), which is exactly what issue #139 says a ContestLog line cannot
 * give: nobody marks a log entry as handled.
 *
 * Putting it in `tasks` would also have meant sharing the per-site
 * task_number sequence with balloon deliveries, so an emergency would sort
 * into the same list as "print this" and inherit its "Concluida" vocabulary.
 * Different queue, different people, different urgency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sos_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();
            // The site is what routes the call: the staff physically present
            // in that room answer it, not the jury and not another site.
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('user_id');

            // Optional, team-written, untrusted. Length-capped here and
            // escaped at render; it is display data and nothing else reads
            // it (it is deliberately kept out of the ContestLog context so
            // the audit screen never renders team-authored text).
            $table->string('note', 200)->nullable();

            $table->enum('status', ['open', 'acknowledged', 'resolved'])->default('open');

            // Seconds from contest start, same convention as runs/tasks/
            // clarifications -- "at 03:41 into the contest" is how an
            // incident gets discussed afterwards, and wall-clock timestamps
            // alone do not answer that.
            $table->integer('contest_time')->comment('Seconds from contest start');

            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedInteger('acknowledged_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('resolved_by')->nullable();

            /*
             * The dedupe column, and the reason the queue cannot be buried.
             *
             * Set to 1 while the call is unresolved and back to NULL the
             * moment it is resolved. Both MySQL/InnoDB and SQLite treat NULLs
             * in a UNIQUE index as distinct from each other, so this unique
             * index is a portable partial index: any number of resolved calls
             * per team, at most ONE unresolved one.
             *
             * That is a stronger guarantee than a time-based cooldown and a
             * much safer one. A cooldown says "you may not ask for help for
             * the next N seconds", which is precisely the wrong answer to
             * give someone whose machine just died; this says "your call is
             * already in the queue", which is true, and it bounds the queue
             * at one row per team no matter how hard the button is pressed.
             * The controller checks for the open call first (so the team gets
             * a sentence instead of a SQL error), and this index is what
             * makes the check hold under two simultaneous double-clicks.
             */
            $table->unsignedTinyInteger('active_slot')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->foreign('acknowledged_by')->references('user_id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by')->references('user_id')->on('users')->nullOnDelete();

            $table->unique(['contest_id', 'site_id', 'user_id', 'active_slot'], 'sos_calls_one_open_per_team');
            // The staff queue's only query: this site's calls, open first.
            $table->index(['contest_id', 'site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sos_calls');
    }
};
