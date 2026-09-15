<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #189 -- the freeze needs an end that somebody chooses.
 *
 * Contest::isFrozen() used to early-return false unless isRunning(), and
 * isRunning() requires now() <= end_time. So the freeze expired with the
 * contest: the full final standings -- including the last hour the freeze
 * exists to hide -- became public to teams and to anonymous visitors at the
 * exact second the clock ran out, while the teams were still leaving the
 * room.
 *
 * In ICPC the freeze outlives the contest on purpose. That IS the ceremony:
 * the hidden hour is revealed on stage, and #44's Animeitor-style webcast
 * assumes a frozen state that lasts until someone ends it.
 *
 * `unfrozen_at` is that decision, recorded. Null means "still frozen", and
 * a contest nobody reveals stays frozen rather than publishing itself --
 * which is the safe direction to fail in.
 *
 * Deliberately a timestamp and not a boolean: "when were the standings
 * released" is the question an appeal asks, and CLICS names the same moment
 * `scoreboard_thaw_time`, so a future Contest API (#195) has somewhere to
 * read it from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contests', function (Blueprint $table) {
            $table->timestamp('unfrozen_at')->nullable()->after('freeze_time');
        });
    }

    public function down(): void
    {
        Schema::table('contests', function (Blueprint $table) {
            $table->dropColumn('unfrozen_at');
        });
    }
};
