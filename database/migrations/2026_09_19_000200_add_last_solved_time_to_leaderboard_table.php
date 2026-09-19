<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #316 -- o terceiro criterio de desempate da ICPC.
 *
 * "Teams who solve the same number of problems are ranked first by least
 * total time and, if need be, by the earliest time of submission of the
 * last accepted run."
 *
 * O minuto do ultimo AC da equipe, SEM penalidade -- a regra fala do tempo
 * de submissao, e nao do tempo consumido. Guardado aqui, ao lado dos outros
 * dois agregados, porque e calculado no mesmo lugar e pela mesma consulta
 * (`Leaderboard::updateForUser()`): uma segunda consulta na hora de ordenar
 * seria uma segunda formulacao da mesma pergunta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leaderboard', function (Blueprint $table) {
            $table->integer('last_solved_time')->default(0)->after('total_time')
                ->comment('Minute of the team last accepted run, without penalty (ICPC third tiebreaker)');
        });
    }

    public function down(): void
    {
        Schema::table('leaderboard', function (Blueprint $table) {
            $table->dropColumn('last_solved_time');
        });
    }
};
