<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #87 -- a balloon task is about a problem, and until now the tasks
 * table had no way to say which one.
 *
 * Without it the only way to recognise "this team already has a balloon for
 * problem C" would be to match on the description text, which breaks the
 * moment anyone rewords it -- and getting that wrong means a rejudge hands
 * the same team a second balloon for the same problem.
 *
 * Nullable because the other kind of task, a team's print request, has no
 * problem attached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('problem_id')->nullable()->after('user_id')
                ->constrained('problems')->cascadeOnDelete();

            // The lookup the balloon guard makes on every accepted run.
            $table->index(['contest_id', 'user_id', 'problem_id'], 'tasks_balloon_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_balloon_lookup_index');
            $table->dropForeign(['problem_id']);
            $table->dropColumn('problem_id');
        });
    }
};
