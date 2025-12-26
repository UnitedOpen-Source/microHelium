<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('extension', 20);
            $table->text('compile_command')->nullable();
            $table->text('run_command')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['contest_id', 'name']);
            $table->unique(['contest_id', 'extension']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
