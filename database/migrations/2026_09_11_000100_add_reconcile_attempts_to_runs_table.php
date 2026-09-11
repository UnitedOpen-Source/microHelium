<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->unsignedTinyInteger('reconcile_attempts')->default(0)->after('status')
                ->comment('Times runs:reconcile-stuck has re-dispatched this run for being pending past its site max_judge_wait_time');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn('reconcile_attempts');
        });
    }
};
