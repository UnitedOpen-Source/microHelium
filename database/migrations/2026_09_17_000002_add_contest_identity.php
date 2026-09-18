<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Issue #271 -- o contest passa a ter identidade fora desta instalação.
 *
 * `contests` tinha `id` e `name`, e mais nada que sirva a um agregado. Um
 * site nacional recebendo trinta pacotes não distingue qual é qual -- `id`
 * é auto-incremento local, então a prova 12 de uma instalação e a prova 12
 * de outra colidem -- e não reconhece o mesmo evento reenviado depois de um
 * rejulgamento.
 *
 * Três colunas:
 *
 *   uuid     identificador estável, gerado uma vez e nunca reescrito. É o
 *            que permite dizer "este pacote é uma nova exportação daquela
 *            prova" em vez de "este é outro evento".
 *   edition  a edição, quando houver ("2026", "XXX")
 *   phase    a fase ("regional", "final nacional", "primeira fase")
 *
 * `edition` e `phase` existem porque uma regional e uma final nacional não
 * são o mesmo tipo de evento no agregado, e o nome da prova sozinho não
 * carrega essa distinção de forma legível por máquina.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contests', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
            $table->string('edition', 50)->nullable()->after('description');
            $table->string('phase', 50)->nullable()->after('edition');
        });

        // Provas que já existem ganham o seu, uma vez.
        //
        // Gerado linha a linha de propósito: um UUID por prova é o ponto, e
        // um `update` único com o mesmo valor daria a todas a mesma
        // identidade -- que é exatamente o defeito que a coluna existe para
        // consertar, escrito pela mão de quem a criou.
        foreach (DB::table('contests')->whereNull('uuid')->pluck('id') as $id) {
            DB::table('contests')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
        }

        // O unique vem DEPOIS do backfill: antes, o `nullable` permitiria
        // uma segunda linha nula e o índice reclamaria em alguns engines.
        Schema::table('contests', function (Blueprint $table) {
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('contests', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn(['uuid', 'edition', 'phase']);
        });
    }
};
