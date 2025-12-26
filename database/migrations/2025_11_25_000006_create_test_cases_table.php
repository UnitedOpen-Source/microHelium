<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('problem_id')->constrained()->cascadeOnDelete();
            $table->integer('number');
            $table->string('input_file');
            $table->string('output_file');
            $table->string('input_hash', 64)->nullable();
            $table->string('output_hash', 64)->nullable();
            $table->boolean('is_sample')->default(false);
            $table->timestamps();

            $table->unique(['problem_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_cases');
    }
};
