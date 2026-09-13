<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #123 -- a fencing token for each claim.
 *
 * judgehost_id answers "which machine", and that turned out not to be
 * enough: two processes of the SAME machine present the same credential, so
 * an agent that hung, was restarted, and woke up later could report a
 * verdict for a run the new process was in the middle of judging. Measured,
 * not hypothesised -- the stale report was accepted with 200 and the
 * correct verdict was then rejected with 409.
 *
 * This is the classic stale-lock-holder problem, and the classic answer is
 * a fencing token: every claim hands out a value, every write carries it,
 * and the resource rejects anything that is not the current one. See
 * docs/specs/53-distributed-judging.md, which asked for exactly this ("DTO
 * de claim inclui ... lease token/expires_at", "Report idempotente por
 * run+attempt+token") before any of this was built.
 *
 * Two columns, because "which claim is current" and "which claim reported"
 * are different questions and conflating them gets the second one wrong.
 * On a successful report the token MOVES from claim_token to
 * reported_claim_token: the claim is over, so it stops being current, but
 * it stays recorded as the one whose verdict landed. That is what makes a
 * retried report idempotent rather than a conflict -- the network can drop
 * after the server has written the verdict and before the agent hears so --
 * while a run judged by a jury member under a live lease still refuses the
 * agent's late answer, because no claim reported it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->string('claim_token', 64)->nullable()->after('claimed_at');
            $table->string('reported_claim_token', 64)->nullable()->after('claim_token');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn(['claim_token', 'reported_claim_token']);
        });
    }
};
