<?php

/*
 * Issue #190 -- esta tabela nao e mais lida nem escrita por nada.
 *
 * A tela de administracao de competicoes rodava aqui, e por isso competicao
 * criada pela API, pelo importador de evento (#147), por seeder ou pelo
 * contest tecnico do Treino Livre (#43) -- todas com linha em `contests` e
 * nenhuma aqui -- era invisivel e nao editavel. As duas tabelas tambem so
 * ficavam em sincronia por coincidencia de auto-increment: tudo a jusante
 * assumia `hackathons.hackathon_id == contests.id`.
 *
 * A migration fica, e a tabela tambem. Apagar tabela e irreversivel, e uma
 * instalacao antiga pode ter dados aqui que alguem ainda queira olhar --
 * diferente do model `Helium\Hackathon`, que era codigo sem referencia e
 * saiu. Se um dia se decidir dropar, que seja com migration propria e com a
 * decisao escrita, como foi a do `roles` no #176.
 */

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateHackathonsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('hackathons', function (Blueprint $table) {
            $table->increments('hackathon_id');
            $table->string('eventName')->unique();
            $table->string('description')->nullable();
            $table->datetime('starts_at');
            $table->datetime('ends_at');
            $table->timestamps();
            $table->SoftDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('hackathons');
    }
}
