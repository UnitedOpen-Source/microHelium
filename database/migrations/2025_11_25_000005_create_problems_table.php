<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('problems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();
            $table->string('short_name', 10);
            $table->string('name', 200);
            $table->string('basename', 100);
            $table->text('description')->nullable();
            $table->string('description_file')->nullable();
            $table->string('input_file')->nullable();
            $table->string('input_file_hash', 64)->nullable();
            $table->string('color_name', 50)->nullable();
            $table->string('color_hex', 7)->nullable();
            $table->integer('time_limit')->default(1)->comment('Time limit in seconds');
            $table->integer('memory_limit')->default(256)->comment('Memory limit in MB');
            $table->integer('output_limit')->default(1024)->comment('Output limit in KB');
            $table->boolean('auto_judge')->default(true);
            $table->boolean('is_fake')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['contest_id', 'short_name']);
            $table->unique(['contest_id', 'basename']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problems');
    }
};
