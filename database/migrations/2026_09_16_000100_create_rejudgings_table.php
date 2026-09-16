<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #192 -- um rejulgamento como CONJUNTO, e nao como uma sequencia de
 * runs mexidos um a um.
 *
 * Os requisitos de CCS pedem duas coisas que nao existiam:
 *
 *   "The CCS must support rejudging of submissions, selected by any
 *    combination of: submission, problem, language, team, time range,
 *    judgement, and judging machine."
 *
 *   "It must be possible to preview the effect of a rejudgement without
 *    committing to it."
 *
 * Prever o efeito exige JULGAR antes de aplicar -- nao ha como saber o que
 * muda sem rodar. Por isso o conjunto: julga-se em paralelo, guarda-se o
 * resultado aqui, e a organizacao decide depois se aplica ou cancela. E a
 * forma que o DOMjudge usa, e a razao e a mesma.
 *
 * `filters` guarda o criterio como foi pedido, e nao so a lista de runs que
 * ele selecionou. As duas coisas respondem perguntas diferentes: a lista
 * diz o que foi feito, o criterio diz por que -- e "todos os envios do
 * problema C em C++" e o que alguem vai querer ler seis meses depois, ao
 * conferir uma classificacao contestada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rejudgings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();

            // Obrigatorio. Um rejulgamento em lote muda a classificacao de
            // gente que nao pediu nada, e "por que" e a primeira pergunta de
            // quem contesta o resultado depois.
            $table->string('reason', 255);

            $table->json('filters');

            // Issue #192, item 4: tirar um AC de uma equipe no meio da prova
            // e a coisa mais cara que um rejulgamento faz. Por padrao os
            // aceitos ficam de fora; incluir e uma decisao explicita, e fica
            // gravada.
            $table->boolean('include_accepted')->default(false);

            // preparing: os membros estao sendo julgados em segundo plano.
            // ready:     todos julgados, esperando a decisao.
            // applied:   os vereditos novos foram gravados nos runs.
            // cancelled: nada foi gravado, e os vereditos antigos ficaram.
            $table->string('status', 20)->default('preparing');

            $table->unsignedInteger('created_by')->nullable();
            $table->foreign('created_by')->references('user_id')->on('users')->nullOnDelete();

            $table->timestamp('applied_at')->nullable();
            $table->unsignedInteger('applied_by')->nullable();
            $table->foreign('applied_by')->references('user_id')->on('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('cancelled_by')->nullable();
            $table->foreign('cancelled_by')->references('user_id')->on('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['contest_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rejudgings');
    }
};
