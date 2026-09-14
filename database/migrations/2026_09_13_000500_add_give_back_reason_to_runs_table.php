<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #125 -- why a judgehost handed a run back, and how often.
 *
 * give-back only reset the status, so the reason lived in a log file on a
 * partner institution's machine that nobody in the organisation reads. A
 * run no host can judge therefore circulated the queue forever: each host
 * took it, returned it, and the next one took it. From the organisers'
 * side it was `pending`, with nothing anywhere saying why -- a team with no
 * verdict and no one with a clue.
 *
 * docs/specs/53-distributed-judging.md asked for the opposite: "Se nenhum
 * executor compativel existir, manter pending com motivo e alerta
 * operacional."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->string('give_back_reason', 40)->nullable()->after('reported_claim_token');
            $table->unsignedSmallInteger('give_back_count')->default(0)->after('give_back_reason');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn(['give_back_reason', 'give_back_count']);
        });
    }
};
