<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('user_id');
            $table->foreignId('problem_id')->constrained()->cascadeOnDelete();
            $table->integer('attempts')->default(0);
            $table->integer('penalty_time')->default(0)->comment('Total penalty time in minutes');
            $table->integer('solved_time')->nullable()->comment('Time when solved in minutes from start');
            $table->boolean('is_solved')->default(false);
            $table->boolean('is_first_solver')->default(false);
            $table->timestamps();

            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();

            $table->unique(['contest_id', 'user_id', 'problem_id']);
            $table->index(['contest_id', 'is_solved']);
        });

        // Aggregated team/user scores for faster leaderboard queries
        Schema::create('leaderboard', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('user_id');
            $table->integer('problems_solved')->default(0);
            $table->integer('total_time')->default(0)->comment('Total time including penalties');
            $table->integer('rank')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();

            $table->unique(['contest_id', 'user_id']);
            $table->index(['contest_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard');
        Schema::dropIfExists('scores');
    }
};
