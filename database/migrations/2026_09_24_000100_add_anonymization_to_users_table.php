<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #395 -- quando e por quem a conta foi anonimizada.
 *
 * "Excluir" uma conta deixou de ser `DELETE`: as chaves estrangeiras com
 * `cascadeOnDelete` levavam runs, scores e leaderboard junto, e o placar de
 * uma prova finalizada mudava. A linha fica, sem os dados pessoais, e estas
 * duas colunas sao o registro de que isso aconteceu -- e o que mantem a
 * conta no placar depois de desabilitada (ScoreboardTeams).
 *
 * `anonymized_by` e unsignedInteger porque `users.user_id` e `increments`
 * (ver 2026_09_11_000200_add_privacy_fields_to_users_table.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('anonymized_at')->nullable()->after('managed_at');
            $table->unsignedInteger('anonymized_by')->nullable()->after('anonymized_at');

            $table->foreign('anonymized_by')->references('user_id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['anonymized_by']);
            $table->dropColumn(['anonymized_at', 'anonymized_by']);
        });
    }
};
