<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #392 -- com qual toolchain cada julgamento foi feito.
 *
 * O #373 (#303) guarda a versao que cada judgehost declara AGORA, em
 * `judgehost_capabilities.version`, e um re-registro a sobrescreve. Nada
 * guardava a versao NO JULGAMENTO: um rejulgamento meses depois, numa
 * maquina reconstruida, podia dar outro veredito sem que houvesse como
 * mostrar que a versao era outra -- nem que era a mesma.
 *
 * Em `runs` porque o julgamento que vale ja mora ali (veredito, medicao da
 * #196, `judgehost_id`), e em `rejudging_runs` porque o julgamento ANTERIOR
 * ja mora ali (#192). Ver docs/specs/392-versao-no-julgamento.md para por
 * que nao uma tabela de julgamentos.
 *
 * 40 caracteres, o mesmo teto de `judgehost_capabilities.version`: e o mesmo
 * dado, vindo da mesma sonda. Null e "nao disse", nunca "nao tem".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->string('toolchain_version', 40)->nullable()->after('measured_cpu_ms');
            $table->string('toolchain_profile', 40)->nullable()->after('toolchain_version');
        });

        Schema::table('rejudging_runs', function (Blueprint $table) {
            $table->string('old_toolchain_version', 40)->nullable()->after('old_verified_at');
            $table->string('old_toolchain_profile', 40)->nullable()->after('old_toolchain_version');
            $table->string('new_toolchain_version', 40)->nullable()->after('new_message');
            $table->string('new_toolchain_profile', 40)->nullable()->after('new_toolchain_version');
        });
    }

    public function down(): void
    {
        Schema::table('rejudging_runs', function (Blueprint $table) {
            $table->dropColumn([
                'old_toolchain_version',
                'old_toolchain_profile',
                'new_toolchain_version',
                'new_toolchain_profile',
            ]);
        });

        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn(['toolchain_version', 'toolchain_profile']);
        });
    }
};
