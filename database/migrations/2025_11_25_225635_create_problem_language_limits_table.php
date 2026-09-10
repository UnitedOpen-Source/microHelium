<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-problem-per-language overrides, matching BOCA's model where
     * problemtable.problemautojudge is a bitmask and each language in the
     * problem package can carry its own limits/<lang> file. Without a row
     * here, Problem::getTimeLimitFor()/getMemoryLimitFor()/isAutoJudgeEnabledFor()
     * fall back to the problem-level defaults.
     */
    public function up(): void
    {
        Schema::create('problem_language_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('problem_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->integer('time_limit')->nullable()->comment('Overrides problems.time_limit (seconds) for this language');
            $table->integer('memory_limit')->nullable()->comment('Overrides problems.memory_limit (MB) for this language');
            $table->boolean('auto_judge_enabled')->nullable()->comment('Overrides problems.auto_judge for this language; null = inherit');
            $table->timestamps();

            $table->unique(['problem_id', 'language_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problem_language_limits');
    }
};
