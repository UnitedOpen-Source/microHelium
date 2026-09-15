<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #197 -- which desk a clarification belongs to.
 *
 * "My keyboard is broken" and "the statement of problem C is ambiguous" go
 * to different people, and until now both landed in one queue with a null
 * `problem_id` as the only hint that the first was not about a problem at
 * all.
 *
 * The CCS requirements name the set: "The CCS must support the following
 * clarification categories: General, SysOps, Operations, and one category
 * per problem." The per-problem half already exists as `problem_id`; this
 * column is the other three.
 *
 * Defaults to `general` rather than null so that every row, including the
 * ones written before this migration, answers the question -- a nullable
 * category would mean "old" in some places and "general" in others, and the
 * filter would have to know which.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clarifications', function (Blueprint $table) {
            $table->string('category', 20)->default('general')->after('problem_id');
            $table->index(['contest_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::table('clarifications', function (Blueprint $table) {
            $table->dropIndex(['contest_id', 'category']);
            $table->dropColumn('category');
        });
    }
};
