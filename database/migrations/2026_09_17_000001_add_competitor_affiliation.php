<?php

use App\Services\CompetitorAffiliationBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #270 -- a instituição pela qual uma equipe compete deixa de ser
 * inferida da tabela de governança do banco de problemas.
 *
 * `organization_memberships` foi criada no #46 para responder "quem pode
 * editar as etiquetas e o dono dos problemas desta organização" -- permissão
 * sobre acervo, com coluna `role` cujo padrão é `editor`. Estava sendo lida
 * como "por qual instituição esta equipe compete", que é outra pergunta:
 *
 *   equipe que não é membro de banco nenhum      -> sem instituição
 *   usuário membro de duas organizações          -> a primeira por id
 *   quem edita o banco de uma e compete por outra -> afiliação errada
 *
 * ## Por que no usuário, e não numa tabela de participação
 *
 * A issue levanta a dúvida: o vínculo é do usuário (vale para todas as
 * provas) ou da participação naquele contest (uma equipe pode representar
 * instituições diferentes em edições diferentes)?
 *
 * A dúvida se dissolve neste repositório, e o `TeamImportCommand` já tinha
 * registrado por quê:
 *
 *   "IDENTITY IS (contest_id, icpc_id). (...) Scoped to the contest because
 *    the same team id genuinely reappears in next year's contest"
 *
 * Uma conta de equipe JÁ É por prova -- `users.contest_id` existe desde
 * 2025_11_25_000007, e a mesma equipe no ano seguinte é outra linha. Então
 * `users.organization_id` é ao mesmo tempo a opção simples e a fiel: ela já
 * é, de fato, afiliação por participação.
 *
 * ## A identidade externa das organizações
 *
 * `organizations` guardava o mínimo -- `id`, `name`, `archived_at`. A Contest
 * API da ICPC define `icpc_id`, `formal_name` e `country` no recurso, e o
 * `ClicsPresenter` preenchia `formal_name` com o próprio `name` por não ter
 * onde ler. Sem identificador externo, um agregado nacional não consegue
 * dizer que a "UFMG" de uma edição é a "UFMG" de outra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // O identificador da instituição no cadastro da ICPC. É a chave
            // que permite a um site nacional casar a mesma universidade
            // entre edições e entre instalações.
            $table->string('icpc_id', 50)->nullable()->after('name');
            // Nulo quer dizer "use o `name`", que é o que o presenter já
            // fazia -- e assim uma instalação existente não precisa
            // preencher nada para continuar respondendo o mesmo.
            $table->string('formal_name', 255)->nullable()->after('icpc_id');
            // ISO 3166-1 alfa-3, que é o que a Contest API usa.
            $table->string('country', 3)->nullable()->after('formal_name');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable()->after('site_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->index('organization_id');
        });

        // Issue #270 -- a derivacao vive em
        // App\Services\CompetitorAffiliationBackfill, e nao aqui.
        //
        // Sem ela, esta migracao acrescentaria uma coluna vazia e toda
        // equipe perderia a instituicao que a Contest API vinha relatando --
        // trocar afiliacao errada por afiliacao nenhuma nao e conserto.
        //
        // Fora da migracao porque uma derivacao de dados que nao pode ser
        // reexecutada nem testada e uma derivacao em que ninguem confia, e
        // quem for auditar o resultado precisa poder rodar de novo e
        // comparar.
        app(CompetitorAffiliationBackfill::class)->run();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['organization_id']);
            $table->dropColumn('organization_id');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['icpc_id', 'formal_name', 'country']);
        });
    }
};
