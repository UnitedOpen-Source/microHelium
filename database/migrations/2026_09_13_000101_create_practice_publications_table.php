<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #43 -- one row per versioned publication of a ProblemBank entry
 * into the practice library (docs/specs/43-practice.md).
 *
 * The point of the table is that a publication is a *snapshot*, not a live
 * view of the bank: "Admin publica snapshot versionado de ProblemBank
 * (enunciado, limites, testes e linguagens) para prática. Editar o banco
 * depois não altera desafios ou resultados existentes."
 *
 * So each publication owns its own `problems` row inside the practice
 * contest, and runs point at that row. Republishing a later bank version
 * closes the current publication (unpublished_at) and opens a new one with
 * a new problem snapshot, which is what keeps earlier submissions attached
 * to the material they were actually judged against -- "republicação [...]
 * não reavalia silenciosamente envios anteriores".
 *
 * Unpublishing only stamps unpublished_at. The problem row and every run
 * against it stay: "Retirar da biblioteca bloqueia novos envios,
 * preservando histórico privado."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('problem_bank_id')->constrained('problem_bank')->cascadeOnDelete();

            // The snapshot this publication serves. Nullable only so the row
            // survives a hard delete of the snapshot; the history it backs
            // is worth more than the pointer.
            $table->foreignId('problem_id')->nullable()->constrained('problems')->nullOnDelete();

            // The bank's `version` at the moment of publication, as a string
            // because that is the shape the API contract uses on the way in
            // and out ({published, version} / {published, practice_problem_id,
            // version}).
            $table->string('version', 40);

            $table->timestamp('published_at');
            $table->timestamp('unpublished_at')->nullable();

            // users.user_id is an unsignedInteger (increments), not a
            // bigIncrements -- foreignId() here would produce a BIGINT and
            // MySQL would reject the constraint with error 3780. Same
            // pattern as create_scores_table and create_account_activations_table.
            $table->unsignedInteger('published_by')->nullable();
            $table->foreign('published_by')->references('user_id')->on('users')->nullOnDelete();

            // sha256 over the exact bank material that was snapshotted, so a
            // republication of identical content is recognisable as such
            // without diffing every column.
            $table->string('source_snapshot_hash', 64);

            $table->timestamps();

            // "Biblioteca contém apenas publicados": the library listing and
            // every submit-time check filter on unpublished_at IS NULL.
            $table->index(['problem_bank_id', 'unpublished_at']);
            $table->index('unpublished_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_publications');
    }
};
