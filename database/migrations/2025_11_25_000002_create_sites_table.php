<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('ip_address', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('permit_logins')->default(true);
            $table->boolean('auto_judge')->default(false);
            $table->integer('duration')->nullable()->comment('Site-specific duration override');
            $table->integer('freeze_time')->nullable()->comment('Site-specific freeze time override');
            $table->integer('max_runtime')->default(600)->comment('Max runtime for submissions in seconds');
            $table->string('chief_judge_name', 50)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['contest_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
