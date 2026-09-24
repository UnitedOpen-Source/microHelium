<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #396 -- currículos oficiais como dado (docs/specs/396-curriculos-oficiais.md).
 *
 * Só o esquema mora aqui. O texto das habilidades muda de versão com o
 * documento oficial e entra pelo `curriculum:import`, a partir de um CSV
 * versionado em database/curricula/, nunca por migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curriculum_frameworks', function (Blueprint $table) {
            $table->id();
            // Chave natural da reimportação: "bncc-computacao", "csta-2017".
            $table->string('slug', 64)->unique();
            $table->string('name', 255);
            // "BR", "GB-ENG", "US": quem publica o documento.
            $table->string('jurisdiction', 16);
            $table->string('version', 32);
            // Idioma original do texto; tradução é assunto da #397.
            $table->string('locale', 16);
            $table->string('source_url', 2048);
            // Quando a fonte foi lida para montar o CSV. Um texto oficial
            // muda de versão; sem esta data ninguém sabe qual foi copiado.
            $table->date('source_consulted_at');
            $table->string('source_sha256', 64)->nullable();
            $table->text('source_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('curriculum_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_framework_id')->constrained('curriculum_frameworks')->cascadeOnDelete();
            // Obrigatório: é o que torna a reimportação idempotente. Currículo
            // em prosa (KS3) recebe um código gerado por quem prepara o CSV.
            $table->string('code', 32);
            // Etapa como o documento a escreve ("6º ano", "KS3"). Nula para
            // currículo sem etapa.
            $table->string('stage', 100)->nullable();
            // Nulo onde o documento não organiza por eixo (o Ensino Médio da
            // BNCC é por competência específica).
            $table->string('axis', 100)->nullable();
            $table->text('text');
            // Ordem do documento.
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['curriculum_framework_id', 'code']);
            $table->index(['curriculum_framework_id', 'position']);
            $table->index('code');
        });

        Schema::create('problem_bank_outcomes', function (Blueprint $table) {
            $table->foreignId('problem_bank_id')->constrained('problem_bank')->cascadeOnDelete();
            $table->foreignId('curriculum_outcome_id')->constrained('curriculum_outcomes')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['problem_bank_id', 'curriculum_outcome_id']);
            $table->index('curriculum_outcome_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problem_bank_outcomes');
        Schema::dropIfExists('curriculum_outcomes');
        Schema::dropIfExists('curriculum_frameworks');
    }
};
