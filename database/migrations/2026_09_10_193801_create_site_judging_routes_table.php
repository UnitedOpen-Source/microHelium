<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_judging_routes', function (Blueprint $table) {
            $table->id();
            // host_site's judges also handle runs originating at source_site.
            $table->foreignId('host_site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('source_site_id')->constrained('sites')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['host_site_id', 'source_site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_judging_routes');
    }
};
