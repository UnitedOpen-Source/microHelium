<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contest_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('ip_address', 100)->nullable();
            $table->enum('type', ['error', 'warning', 'info', 'debug'])->default('info');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('user_id')->on('users')->nullOnDelete();

            $table->index(['contest_id', 'type']);
            $table->index(['contest_id', 'user_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contest_logs');
    }
};
