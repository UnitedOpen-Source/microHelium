<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #117 -- which languages a judge machine can actually run.
 *
 * Capability here means COMPATIBILITY, not permission and not speed, which
 * is what docs/specs/53-distributed-judging.md said: "Capacidades sao
 * compatibilidades efetivas de linguagem/runtime, nao permissoes."
 *
 * Deliberately NOT a performance rating. The ICPC CCS system requirements
 * describe "auto-judging machines that are identical to the team machines",
 * and DOMjudge documents uniform hardware as how a fair reproducible setup
 * is obtained; no contest system normalises a time limit by host benchmark.
 * Routing by speed would invent machinery the field solves by policy, and
 * bad machinery at that -- a factor measured once tracks neither cache
 * contention nor thermal throttling, so it would feel fair while TLE went
 * on varying. Hardware divergence is surfaced to organisers instead (see
 * judgehosts.cpu_count).
 *
 * Rows are derived by the agent from the machine, not configured: a list
 * someone types is a list that can be wrong, and being wrong here means a
 * host repeatedly taking work it cannot do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('judgehost_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('judgehost_id')->constrained()->cascadeOnDelete();
            $table->string('extension', 20);
            $table->timestamps();

            $table->unique(['judgehost_id', 'extension']);
            $table->index('extension');
        });

        Schema::table('judgehosts', function (Blueprint $table) {
            // Reported, never acted on: an organiser is told when judge
            // machines diverge, rather than the server silently
            // compensating for it.
            $table->unsignedSmallInteger('cpu_count')->nullable()->after('enabled');
            $table->unsignedInteger('memory_mb')->nullable()->after('cpu_count');
        });
    }

    public function down(): void
    {
        Schema::table('judgehosts', function (Blueprint $table) {
            $table->dropColumn(['cpu_count', 'memory_mb']);
        });

        Schema::dropIfExists('judgehost_capabilities');
    }
};
