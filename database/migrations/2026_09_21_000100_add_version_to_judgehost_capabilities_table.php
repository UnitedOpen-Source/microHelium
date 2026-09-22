<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #303 -- a capacidade declarada passa a dizer QUAL versao.
 *
 * A spec do julgamento distribuido ja prometia isso ("DTO de claim inclui
 * run/attempt IDs, versao de linguagem, limites..."), e o codigo declarava
 * so a extensao. O efeito e o que a #303 descreve: dois judgehosts de um
 * parque com imagens diferentes -- um com GCC 13, outro com GCC 15 --
 * declaram capacidade identica, e nada registra qual deles julgou o que. Um
 * rejulgamento pode dar outra resposta sem que nada acuse.
 *
 * Fixar o pino no Dockerfile (#303, lado 2) NAO substitui isto: o pino diz
 * o que a nossa imagem manda instalar, e a maquina que importa e a da
 * instituicao parceira, que construiu a imagem dela.
 *
 * ## Anulavel de proposito, e nunca um portao
 *
 * `null` quer dizer "este host nao disse", e isso e o caso de todo agente
 * anterior a esta mudanca -- e tambem de um toolchain que demorou demais
 * para se identificar. A coluna e informacao anexa a capacidade: quem
 * decide se um host pode julgar continua sendo `Judgehost::canJudge()`, por
 * PRESENCA da extensao. A #354 mostrou o custo de uma lista de capacidades
 * PARCIAL -- pior que vazia, porque parece correta -- e uma versao ausente
 * nao pode virar uma recusa.
 *
 * 40 caracteres porque o que se guarda e o numero que o toolchain imprime
 * ("15.2.0", "2.49.93+", "3.2.2"), e nao a linha inteira.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('judgehost_capabilities', function (Blueprint $table) {
            $table->string('version', 40)->nullable()->after('extension');
        });
    }

    public function down(): void
    {
        Schema::table('judgehost_capabilities', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
