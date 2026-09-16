<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #196 -- quanto tempo o julgamento realmente levou.
 *
 * Ate aqui nada disso era gravado. O TLE vem do codigo de saida do
 * `ulimit -t`, entao o sistema sabia SE o envio estourou o limite e nunca
 * QUANTO ele demorou -- e sem isso nao da para responder "as minhas
 * maquinas sao comparaveis?", que e a pergunta que o #130 deixou em aberto
 * ao decidir que hardware heterogeneo e avisado e nao compensado.
 *
 * O #117/#130 tomou essa decisao de proposito e ela e honesta, mas deixa um
 * buraco: com maquinas de velocidades diferentes -- que e o caso quando
 * instituicoes parceiras emprestam o que tem (#53) -- o mesmo limite e
 * generoso numa e apertado noutra, e a equipe recebe TLE ou AC dependendo de
 * qual maquina pegou o run. Nada no sistema dizia isso.
 *
 * Esta e a fase 1 que a propria issue recomenda: MEDIR E AVISAR. Nenhum
 * veredito muda. `runs.judgehost_id` ja existe desde o #53, entao a medicao
 * ja nasce atribuida a maquina que a produziu.
 *
 * Dois numeros e nao um: o de PAREDE e o que a equipe sente; o de CPU e o
 * que compara maquinas sem ser enganado por uma que estava ocupada com outro
 * julgamento ao mesmo tempo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            // Milissegundos inteiros, e nao segundos decimais: `float` para
            // uma medida que vai ser somada e comparada convida a diferenca
            // de arredondamento aparecer numa tabela de divergencia entre
            // maquinas, que e justamente o que esta coluna existe para
            // medir.
            $table->unsignedInteger('measured_wall_ms')->nullable()->after('auto_judge_result');
            $table->unsignedInteger('measured_cpu_ms')->nullable()->after('measured_wall_ms');

            // A comparacao entre maquinas le por (problema, linguagem,
            // judgehost).
            $table->index(['problem_id', 'language_id', 'judgehost_id'], 'runs_measured_time_index');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropIndex('runs_measured_time_index');
            $table->dropColumn(['measured_wall_ms', 'measured_cpu_ms']);
        });
    }
};
