<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #202 -- o estado que vem depois de "terminou".
 *
 * Nao existia nenhum. O congelamento acaba quando alguem revela (#189), e
 * ali a prova simplesmente para de ter estado: nao ha o momento em que a
 * organizacao AFIRMA que nao sobrou nada pendente que pudesse mudar a
 * classificacao.
 *
 * Os requisitos de CCS tratam finalizar como checagem de integridade da
 * prova inteira, e nao como um botao de publicar -- a lista do que impede
 * finalizar e a parte interessante:
 *
 *   "Finalizing must not be possible if: The contest is still running...;
 *    There are un-judged submissions; There are submissions judged as
 *    Judging Error; There are unanswered clarification requests."
 *
 * As colunas de premiacao vem junto porque medalha deriva de colocacao, e
 * colocacao so existe depois do corte -- as tres coisas sao um assunto so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contests', function (Blueprint $table) {
            $table->timestamp('finalized_at')->nullable()->after('unfrozen_at');
            $table->unsignedInteger('finalized_by')->nullable()->after('finalized_at');
            $table->foreign('finalized_by')->references('user_id')->on('users')->nullOnDelete();

            // "Teams that solved fewer problems than the median team are not
            // ranked at all" -- regra normativa da ICPC.
            //
            // Configuravel porque a issue pergunta e a resposta e sim: uma
            // prova de treino ou uma seletiva interna quer classificar todo
            // mundo, e aplicar a mediana la deixaria metade da turma sem
            // colocacao por um motivo que nao existe naquele contexto. O
            // padrao e o da ICPC, porque e para isso que este sistema serve
            // primeiro.
            $table->boolean('rank_median_cut')->default(true)->after('finalized_by');

            // Zero de proposito, e nao 4/4/4 como na final mundial.
            //
            // Declarar medalhista e uma afirmacao para fora. Um sistema que
            // sai premiando ouro numa prova de treino porque ninguem mexeu
            // na configuracao e pior do que um que exige dizer quantas.
            $table->unsignedSmallInteger('medal_gold')->default(0)->after('rank_median_cut');
            $table->unsignedSmallInteger('medal_silver')->default(0)->after('medal_gold');
            $table->unsignedSmallInteger('medal_bronze')->default(0)->after('medal_silver');
        });
    }

    public function down(): void
    {
        Schema::table('contests', function (Blueprint $table) {
            $table->dropForeign(['finalized_by']);
            $table->dropColumn([
                'finalized_at',
                'finalized_by',
                'rank_median_cut',
                'medal_gold',
                'medal_silver',
                'medal_bronze',
            ]);
        });
    }
};
