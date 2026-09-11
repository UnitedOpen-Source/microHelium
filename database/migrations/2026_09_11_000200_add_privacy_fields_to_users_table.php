<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #47 -- contas gerenciadas e privacidade. `users.user_id` is an
 * unsignedInteger (`$table->increments('user_id')`, see
 * 2017_06_17_011612_create_users_table.php), not unsignedBigInteger, so
 * `managed_by`'s column type/foreign key must match that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('birthdate')->nullable()->after('icpc_id');
            $table->unsignedInteger('managed_by')->nullable()->after('birthdate');
            $table->string('profile_visibility', 20)->default('private')->after('managed_by');
            $table->timestamp('managed_at')->nullable()->after('profile_visibility');

            $table->foreign('managed_by')->references('user_id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['managed_by']);
            $table->dropColumn(['birthdate', 'managed_by', 'profile_visibility', 'managed_at']);
        });
    }
};
