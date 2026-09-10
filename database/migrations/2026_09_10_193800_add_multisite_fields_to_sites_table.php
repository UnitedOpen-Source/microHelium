<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('score_visibility', 20)->default('all')->after('chief_judge_name')
                ->comment("'all': teams at this site see the full scoreboard. 'own_site': only this site's teams.");
            $table->integer('max_judge_wait_time')->default(900)->after('score_visibility')
                ->comment('Seconds a pending run can wait before being flagged as overdue for this site.');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['score_visibility', 'max_judge_wait_time']);
        });
    }
};
