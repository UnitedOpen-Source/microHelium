<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #192 -- um membro do conjunto, com o veredito ANTIGO preservado.
 *
 * Hoje o rejulgamento APAGA o veredito anterior (`answer_id = null` em
 * Api\RunController::rejudge()), entao nao ha como comparar antes/depois
 * nem como desfazer. O DOMjudge guarda:
 *
 *   "When a submission is rejudged, the old judging data is kept but
 *    marked as invalid."
 *
 * Aqui o lugar do "antigo" e esta tabela. O run continua com o veredito
 * valendo ate o conjunto ser aplicado -- e essa e a diferenca que permite a
 * previa: enquanto se decide, a equipe continua vendo o que ja via, e a
 * classificacao nao se mexe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rejudging_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rejudging_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();

            // O estado do run no momento em que o conjunto foi montado.
            $table->unsignedBigInteger('old_answer_id')->nullable();
            $table->string('old_status', 20)->nullable();
            $table->integer('old_judged_time')->nullable();
            $table->timestamp('old_verified_at')->nullable();

            // O resultado do julgamento de sombra. Null enquanto nao rodou.
            $table->unsignedBigInteger('new_answer_id')->nullable();
            $table->string('new_verdict', 20)->nullable();
            $table->text('new_message')->nullable();
            $table->timestamp('judged_at')->nullable();

            // Quando o julgamento de sombra falhou por completo -- o erro,
            // e nao um veredito. Aplicar um conjunto com membro nesse estado
            // trocaria um veredito real por uma falha de infraestrutura.
            $table->text('error')->nullable();

            $table->timestamps();

            // Um run nao pode entrar duas vezes no mesmo conjunto: seria
            // julgado duas vezes e a previa contaria a mudanca em dobro.
            $table->unique(['rejudging_id', 'run_id']);
            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rejudging_runs');
    }
};
