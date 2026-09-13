<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #43 -- Treino Livre.
 *
 * The practice library reuses the existing Contest/Problem/Run/Score
 * pipeline rather than growing a parallel one, which means practice needs a
 * Contest row to hang off. docs/specs/43-practice.md is explicit about how
 * that row must be recognised: "Criar um único concurso técnico de prática,
 * identificado por campo/tipo explícito e nunca por nome." Hence a column,
 * not a magic name, not a reserved id.
 *
 * Everything that means "a competition" has to exclude it -- active-contest
 * selection, the public selector, the clock, global activation, event CSV,
 * tasks/balloons and event ranking. Contest::competition() is the scope that
 * does it; see tests/Feature/Practice/PracticeContestIsolationTest.php for
 * the regression net.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contests', function (Blueprint $table) {
            $table->boolean('is_practice')->default(false)->after('is_public');
            $table->index('is_practice');
        });
    }

    public function down(): void
    {
        Schema::table('contests', function (Blueprint $table) {
            $table->dropIndex(['is_practice']);
            $table->dropColumn('is_practice');
        });
    }
};
