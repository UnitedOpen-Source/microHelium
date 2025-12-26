<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('contest_id')->nullable()->after('user_id');
            $table->unsignedBigInteger('site_id')->nullable()->after('contest_id');
            $table->string('user_type', 20)->default('team')->after('email')->comment('admin, judge, team, staff, score');
            $table->text('description')->nullable()->after('user_type');
            $table->boolean('is_enabled')->default(true)->after('description');
            $table->boolean('multi_login')->default(false)->after('is_enabled');
            $table->string('permitted_ip', 300)->nullable()->after('multi_login');
            $table->string('last_ip', 100)->nullable()->after('permitted_ip');
            $table->timestamp('last_login_at')->nullable()->after('last_ip');
            $table->string('icpc_id', 50)->nullable()->after('last_login_at');

            $table->foreign('contest_id')->references('id')->on('contests')->nullOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['contest_id']);
            $table->dropForeign(['site_id']);
            $table->dropColumn([
                'contest_id', 'site_id', 'user_type', 'description',
                'is_enabled', 'multi_login', 'permitted_ip', 'last_ip',
                'last_login_at', 'icpc_id'
            ]);
        });
    }
};
