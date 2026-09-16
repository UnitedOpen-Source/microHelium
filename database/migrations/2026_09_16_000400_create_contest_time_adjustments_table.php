<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #198 -- um pedaco de tempo que a prova nao conta.
 *
 * Cai a energia numa sede as 14h e volta as 14h40. As equipes daquela sede
 * perderam 40 minutos e as outras nao. Hoje nao ha o que fazer: o relogio e
 * `start_time` + `duration`, e as opcoes sao deixar a sede perder o tempo ou
 * mexer no start_time de todo mundo -- que estraga os contest_time ja
 * gravados em cada run.
 *
 * Os requisitos de CCS tratam isso como obrigatorio:
 *
 *   "The CCS must support removing time intervals from the contest."
 *   "The removal of a time interval must be reversible."
 *   "If submission S_i arrived before submission S_j during a removed
 *    interval, S_i must still be considered by the CCS to have arrived
 *    strictly before S_j."
 *
 * E nao e hipotese: o Manual do Diretor de Sede da Maratona tem protocolo
 * escrito para queda de energia -- "adiciona-se 60% do tempo de parada,
 * limitado a uma hora". Hoje isso e impossivel de aplicar no sistema, entao
 * a sede faz a conta no papel e a classificacao nao bate com o placar.
 *
 * `site_id` NULAVEL e o que permite as duas formas com um mecanismo so: nulo
 * e a prova inteira (o intervalo removido que a ICPC especifica), preenchido
 * e so aquela sede (a extensao por sede que o MOJ faz, e que e o caso real
 * da Maratona). Fazer duas maquinas para a mesma pergunta era a alternativa,
 * e seria duas.
 *
 * SoftDeletes e a reversibilidade. "Deve ser reversivel" nao combina com
 * apagar a linha: desfazer precisa poder ser auditado, e alguem vai
 * perguntar depois por que aquela sede teve 40 minutos a mais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contest_time_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();

            // Nulo = a prova inteira.
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();

            // Instantes de PAREDE, e nao tempo de prova: a queda de energia
            // aconteceu as 14h, e quem registra sabe o horario do relogio da
            // parede. Converter para tempo de prova na hora de gravar
            // congelaria a conversao usando o relogio de ENTAO -- e se um
            // segundo intervalo for removido antes deste, a conversao muda.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            // Obrigatorio. Um pedaco de prova que nao conta muda a
            // classificacao de gente que nao pediu nada, e "por que" e a
            // primeira pergunta de quem contesta o resultado.
            $table->string('reason', 255);

            $table->unsignedInteger('created_by')->nullable();
            $table->foreign('created_by')->references('user_id')->on('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['contest_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contest_time_adjustments');
    }
};
