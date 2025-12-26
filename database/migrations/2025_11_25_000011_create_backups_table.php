<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('user_id');
            $table->integer('backup_number');
            $table->string('filename', 200);
            $table->string('file_path');
            $table->integer('file_size')->comment('Size in bytes');
            $table->enum('status', ['active', 'deleted'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('user_id')->on('users')->cascadeOnDelete();

            $table->unique(['contest_id', 'site_id', 'backup_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
