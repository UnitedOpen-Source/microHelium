<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('user_id');
            $table->foreignId('problem_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->foreignId('answer_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('run_number');
            $table->string('filename', 200);
            $table->string('source_file');
            $table->string('source_hash', 64);
            $table->integer('contest_time')->comment('Seconds from contest start');
            $table->integer('judged_time')->nullable()->comment('Seconds when judged');
            $table->enum('status', ['pending', 'judging', 'judged', 'rejudging', 'deleted'])->default('pending');

            // Judge info
            $table->unsignedInteger('judge_id')->nullable();
            $table->foreignId('judge_site_id')->nullable()->constrained('sites')->nullOnDelete();

            // Multi-judge support
            $table->foreignId('answer1_id')->nullable()->constrained('answers')->nullOnDelete();
            $table->unsignedInteger('judge1_id')->nullable();
            $table->foreignId('answer2_id')->nullable()->constrained('answers')->nullOnDelete();
            $table->unsignedInteger('judge2_id')->nullable();

            // Auto-judge info
            $table->string('auto_judge_ip', 50)->nullable();
            $table->timestamp('auto_judge_start')->nullable();
            $table->timestamp('auto_judge_end')->nullable();
            $table->text('auto_judge_result')->nullable();
            $table->text('auto_judge_stdout')->nullable();
            $table->text('auto_judge_stderr')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Foreign keys to users table (which uses user_id as PK)
            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->foreign('judge_id')->references('user_id')->on('users')->nullOnDelete();
            $table->foreign('judge1_id')->references('user_id')->on('users')->nullOnDelete();
            $table->foreign('judge2_id')->references('user_id')->on('users')->nullOnDelete();

            $table->unique(['contest_id', 'site_id', 'run_number']);
            $table->index(['contest_id', 'user_id', 'problem_id']);
            $table->index(['contest_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};
