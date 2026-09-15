<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #138 -- the verification gate, per contest.
 *
 * DOMjudge carries this as a single installation-wide boolean
 * (`verification_required`, "Is manual verification of judgings by jury
 * required before publication?"). Here it is a column on `contests` and not
 * a config key because this installation hosts more than one event off the
 * same database: a practice contest (#43) has no jury standing by, so a
 * global flag would either stall every practice submission behind a human
 * or force the real event to run ungated. Per contest, each event answers
 * for itself.
 *
 * Default false: turning the gate on delays every verdict the teams see, so
 * it is a decision an organiser makes, never one they inherit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contests', function (Blueprint $table) {
            $table->boolean('verification_required')->default(false)->after('is_practice');
        });
    }

    public function down(): void
    {
        Schema::table('contests', function (Blueprint $table) {
            $table->dropColumn('verification_required');
        });
    }
};
