<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('short_name', 10);
            $table->boolean('is_accepted')->default(false);
            $table->boolean('is_fake')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['contest_id', 'name']);
            $table->unique(['contest_id', 'short_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answers');
    }
};
