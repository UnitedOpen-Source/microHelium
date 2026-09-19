<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issues #321 e #322 -- "esta resposta conta como tentativa da equipe?".
 *
 * Ate aqui a unica coisa que decidia o assunto era `is_accepted`: todo
 * veredito nao aceito custava uma tentativa e uma penalidade. Isso trata do
 * mesmo jeito duas coisas diferentes:
 *
 *  - um veredito sobre o codigo da equipe (WA, TLE, RE, PE, CE), que e culpa
 *    dela e paga penalidade;
 *  - um veredito sobre a NOSSA infraestrutura (CS, escrito por
 *    AutoJudgeService::handleJudgingError() e pelo watchdog do #45 quando o
 *    julgamento falha ou estoura o prazo), que nao e.
 *
 * A coluna e por contest porque `answers` ja e por contest -- e por isso ela
 * tambem responde a pergunta do #322 sobre o CE sem coluna nova em
 * `contests`: uma prova que nao queira penalizar erro de compilacao desliga
 * a marca no CE daquela prova. O padrao continua sendo penalizar, que e o
 * que o BOCA faz e o que a banca da Maratona espera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('answers', function (Blueprint $table) {
            $table->boolean('counts_as_attempt')->default(true)->after('is_accepted');
        });

        // As provas ja existentes tambem param de cobrar pelo CS: a
        // penalidade indevida do #321 e retroativa enquanto a linha estiver
        // marcada, e uma prova em andamento no momento do deploy e
        // exatamente o caso que a issue descreve.
        DB::table('answers')->where('short_name', 'CS')->update(['counts_as_attempt' => false]);
    }

    public function down(): void
    {
        Schema::table('answers', function (Blueprint $table) {
            $table->dropColumn('counts_as_attempt');
        });
    }
};
