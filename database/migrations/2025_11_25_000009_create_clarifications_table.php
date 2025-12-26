<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('user_id');
            $table->foreignId('problem_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('clarification_number');
            $table->text('question');
            $table->text('answer')->nullable();
            $table->integer('contest_time')->comment('Seconds from contest start');
            $table->integer('answered_time')->nullable()->comment('Seconds when answered');
            $table->enum('status', ['pending', 'answering', 'answered', 'broadcast_site', 'broadcast_all', 'deleted'])->default('pending');
            $table->unsignedInteger('judge_id')->nullable();
            $table->foreignId('judge_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->foreign('judge_id')->references('user_id')->on('users')->nullOnDelete();

            $table->unique(['contest_id', 'site_id', 'clarification_number']);
            $table->index(['contest_id', 'user_id']);
            $table->index(['contest_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarifications');
    }
};
