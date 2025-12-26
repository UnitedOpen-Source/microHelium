<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('problem_bank', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            $table->text('description');
            $table->text('input_description');
            $table->text('output_description');
            $table->text('sample_input')->nullable();
            $table->text('sample_output')->nullable();
            $table->text('notes')->nullable();
            $table->integer('time_limit')->default(1);
            $table->integer('memory_limit')->default(256);
            $table->string('source', 100)->default('SPOJ');
            $table->string('source_url', 500)->nullable();
            $table->enum('difficulty', ['easy', 'medium', 'hard'])->default('medium');
            $table->json('tags')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('problem_bank');
    }
};
