<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->timestamp('start_time')->nullable();
            $table->integer('duration')->default(300)->comment('Duration in minutes');
            $table->integer('freeze_time')->default(60)->comment('Minutes before end to freeze scoreboard');
            $table->integer('penalty')->default(20)->comment('Penalty per wrong submission in minutes');
            $table->integer('max_file_size')->default(100)->comment('Max submission file size in KB');
            $table->boolean('is_active')->default(false);
            $table->boolean('is_public')->default(false);
            $table->string('unlock_key', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contests');
    }
};
