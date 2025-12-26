<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('user_id');
            $table->integer('task_number');
            $table->string('description', 200);
            $table->string('filename', 100)->nullable();
            $table->string('file_path')->nullable();
            $table->integer('contest_time')->comment('Seconds from contest start');
            $table->integer('completed_time')->nullable();
            $table->enum('status', ['pending', 'processing', 'done', 'deleted'])->default('pending');
            $table->boolean('is_system')->default(false);
            $table->string('color_name', 50)->nullable();
            $table->string('color_hex', 7)->nullable();
            $table->unsignedInteger('staff_id')->nullable();
            $table->foreignId('staff_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();
            $table->foreign('staff_id')->references('user_id')->on('users')->nullOnDelete();

            $table->unique(['contest_id', 'site_id', 'task_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
