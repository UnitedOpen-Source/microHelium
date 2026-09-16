<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #219 -- o log de mudancas que o event feed le.
 *
 * O #195 entregou a fase 1 da Contest API: os endpoints REST. Falta o event
 * feed, e e ele que o resolver le -- a documentacao do ICPC Tools e direta:
 * "the only part of the Contest API that is strictly required is the event
 * feed and any file references that the feed refers to".
 *
 * A parte cara nao e o endpoint; e ESTA TABELA. Um feed com `since_token`
 * exige um log duravel e totalmente ordenado: um cliente que caiu volta
 * dizendo "recebi ate o token N" e tem que receber exatamente o que veio
 * depois, na mesma ordem. Um feed que reinicia do zero quando o processo cai
 * e um feed que o resolver nao consegue usar.
 *
 * O `id` E o token. Autoincremento e monotonico e unico, e usar um UUID ou
 * um timestamp aqui criaria a pergunta "qual veio antes" para dois eventos
 * do mesmo milissegundo -- que e exatamente a pergunta que o token existe
 * para nao precisar fazer.
 *
 * So o que MUDA durante a prova entra aqui: envio, julgamento e estado. Os
 * objetos estaticos (contest, problemas, equipes, organizacoes, grupos,
 * linguagens, tipos de veredito) sao emitidos como fotografia no inicio do
 * feed, a partir do estado atual. E como os feeds de CLICS funcionam, e
 * poupa um log para dados que nao mudam no meio de uma prova.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contest_events', function (Blueprint $table) {
            // O token. Ver o comentario acima.
            $table->id();
            $table->foreignId('contest_id')->constrained()->cascadeOnDelete();

            // `submissions`, `judgements`, `state` -- o nome do endpoint da
            // Contest API a que o evento pertence.
            $table->string('type', 40);

            // String porque na Contest API todo id e string, inclusive
            // quando a chave aqui e numerica. Guardar inteiro obrigaria a
            // converter na leitura e deixaria a porta aberta para um
            // consumidor comparar com === e falhar em todas.
            $table->string('object_id', 80);

            $table->string('op', 10)->default('create');

            // O objeto como a Contest API o descreve, ja traduzido. Gravar
            // traduzido e nao cru e o que permite que o feed REPITA o
            // passado: o objeto pode ter mudado desde entao, e o feed tem
            // que contar o que era verdade naquele token.
            $table->json('payload');

            /*
             * Issue #219 -- o congelamento, que aqui e mais dificil do que
             * na fase 1.
             *
             * No REST basta filtrar uma lista. Num STREAM nao da para
             * simplesmente omitir o julgamento da janela de congelamento: se
             * ele nunca for emitido, o cliente nunca fica sabendo; se for
             * emitido na hora, vaza. Ele precisa aparecer NO
             * DESCONGELAMENTO, na ordem certa.
             *
             * Por isso a marca fica no evento: o feed publico segura os
             * marcados ate o contest ser descongelado, e ai os entrega na
             * ordem do token -- que e a ordem em que aconteceram.
             */
            $table->boolean('after_freeze')->default(false);

            $table->timestamp('created_at')->nullable();

            $table->index(['contest_id', 'id']);
            $table->index(['contest_id', 'after_freeze']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contest_events');
    }
};
