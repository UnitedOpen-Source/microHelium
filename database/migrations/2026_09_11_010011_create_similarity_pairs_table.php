<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('similarity_pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('similarity_check_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id_a')->constrained('runs')->cascadeOnDelete();
            $table->foreignId('run_id_b')->constrained('runs')->cascadeOnDelete();
            $table->decimal('similarity_score', 5, 2)->unsigned();
            $table->timestamps();

            // App code always writes run_id_a < run_id_b, so this unique
            // index (rather than a functional min/max one, which SQLite --
            // the test driver -- can't express the same way MySQL can)
            // still prevents a duplicate row for the same pair.
            $table->unique(['similarity_check_id', 'run_id_a', 'run_id_b']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('similarity_pairs');
    }
};
