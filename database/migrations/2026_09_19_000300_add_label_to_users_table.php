<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #332 -- o rotulo curto da equipe, que e o que vai ao telao.
 *
 * `team.json` de 2023-06 tem `"required": ["id","name","label"]`, e a spec
 * define `label` como "Label of the team, at WFs normally the team seat
 * number". Era a ultima das onze violacoes de schema, e a unica que nao era
 * traducao: nao havia onde ler.
 *
 * As tres candidatas que ja existiam, e por que nenhuma serve sozinha:
 *
 *   user_id   e o que ja sai em `id`. Emitir o mesmo numero duas vezes nao
 *             acrescenta informacao nenhuma -- e e exatamente o que um
 *             consumidor ja faz quando o campo falta.
 *   username  em instalacao que usa e-mail como login, poria dado pessoal
 *             na tela da cerimonia.
 *   icpc_id   muitas equipes nao tem, e o proprio repositorio o classifica
 *             como identificador PESSOAL (ResultsBundleBuilder). Um rotulo
 *             publico nao e lugar para ele.
 *
 * Por isso uma coluna: o numero do crachá existe no mundo, e nao e derivavel
 * de nada que esta aqui. NULO quer dizer "use o padrao", que e o mesmo
 * contrato que `organizations.formal_name` estabeleceu no #270 -- uma
 * instalacao existente continua respondendo sem preencher nada.
 *
 * Deliberadamente SEM unique. O rotulo e unico por prova, nao por
 * instalacao: duas provas diferentes tem uma equipe "12" cada, e a coluna
 * mora em `users`, que e global. Um unique global recusaria o cadastro
 * correto da segunda prova.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 32 e folgado de proposito: o caso comum e "12" ou "SP-07", e o
            // limite existe so para impedir que uma frase inteira va parar
            // num campo que a cerimonia mostra em corpo grande.
            $table->string('label', 32)->nullable()->after('icpc_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
