<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('similarity_checks', function (Blueprint $table) {
            $table->id();
            // Actor who requested the run (issue #42's "ator").
            $table->unsignedInteger('user_id');
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('problem_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['queued', 'running', 'completed', 'failed'])->default('queued');
            $table->unsignedTinyInteger('threshold');
            $table->unsignedInteger('team_count')->default(0);
            // Snapshot of the run IDs/hashes compared, plus excluded-team
            // counts/reasons -- captured at enqueue time so a later run
            // (more ACs, deleted sources) can never silently change what a
            // completed report claims to have compared.
            $table->json('snapshot');
            // Set from the real JPlag runInformation.json once the job
            // completes; null while queued/running.
            $table->string('engine_version')->nullable();
            // Engine configuration snapshot captured at enqueue time
            // (jplag version, max pairs, whether base-code exclusion was
            // active) -- audit trail independent of `engine_version`.
            $table->json('options')->nullable();
            $table->string('safe_error_code')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->index(['problem_id', 'language_id']);
            $table->index(['contest_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('similarity_checks');
    }
};
