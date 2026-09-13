<?php

namespace App\Services;

use App\Models\Judgehost;
use App\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * Issue #53 -- handing runs out to judge machines, and taking them back.
 *
 * Three things this does that the single-worker loop it replaces did not:
 *
 * 1. Claims atomically. AutoJudgeService::getNextPendingRun() selects the
 *    oldest pending run and returns it; two workers asking at the same
 *    moment both get it and both judge it. Fine with one worker, wrong with
 *    fifty.
 * 2. Gives work back when a host says it lost it -- DOMjudge's idiom, where
 *    registering returns the list of judgings just reset, called on boot,
 *    on endpoint error, and after any failed fetch.
 * 3. Expires leases server-side, which DOMjudge does not: its give-back
 *    only fires when a host comes back, so one that dies leaves its runs
 *    assigned forever (DOMjudge#2476, a World Finals judging that stuck and
 *    needed a manual rejudge).
 */
class JudgeWorkQueue
{
    /**
     * The oldest pending run this host may take, claimed in the same
     * transaction that hands it over so no two hosts can hold it.
     */
    public function claimNext(Judgehost $judgehost): ?Run
    {
        return DB::transaction(function () use ($judgehost) {
            // auto_judge can be overridden per language
            // (problem_language_limits), so the candidate set cannot be
            // narrowed in SQL alone -- the same reason
            // AutoJudgeService::getNextPendingRun() filters in PHP.
            $candidates = Run::query()
                ->where('status', 'pending')
                ->whereNull('judgehost_id')
                ->with(['problem.languageLimits', 'language'])
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            $run = $candidates->first(
                fn (Run $run) => $run->problem && $run->language
                    && $run->problem->isAutoJudgeEnabledFor($run->language)
            );

            if (! $run) {
                return null;
            }

            $run->update([
                'status' => 'judging',
                'judgehost_id' => $judgehost->id,
                'claimed_at' => now(),
            ]);

            return $run->fresh();
        });
    }

    /**
     * Returns this host's unfinished runs to the queue, and reports how
     * many. Called when a host registers: it is the host announcing its own
     * amnesia rather than the server guessing.
     *
     * Only runs still in `judging` are touched. One that finished and was
     * reported is not this host's to give back.
     */
    public function giveBack(Judgehost $judgehost): int
    {
        return Run::query()
            ->where('judgehost_id', $judgehost->id)
            ->where('status', 'judging')
            ->update([
                'status' => 'pending',
                'judgehost_id' => null,
                'claimed_at' => null,
            ]);
    }

    /**
     * Returns runs whose holder has gone quiet for longer than the lease,
     * and reports how many.
     *
     * This is the half a give-back-on-register protocol cannot cover: a
     * machine that dies and never comes back never gives anything back.
     */
    public function expireStaleLeases(?int $leaseSeconds = null): int
    {
        $leaseSeconds ??= (int) config('judgehost.lease_seconds', 600);

        return Run::query()
            ->where('status', 'judging')
            ->whereNotNull('judgehost_id')
            ->where('claimed_at', '<', now()->subSeconds($leaseSeconds))
            ->update([
                'status' => 'pending',
                'judgehost_id' => null,
                'claimed_at' => null,
            ]);
    }

    /**
     * Everything a judge machine needs to know about a run to judge it,
     * short of the file bytes -- those come from their own endpoints, each
     * scoped to the host actually holding the run.
     *
     * @return array<string, mixed>
     */
    public function workPayload(Run $run): array
    {
        $problem = $run->problem;
        $language = $run->language;

        return [
            'run_id' => $run->id,
            'run_number' => $run->run_number,
            'contest_id' => $run->contest_id,
            'problem' => [
                'id' => $problem->id,
                'short_name' => $problem->short_name,
                'time_limit' => $problem->getTimeLimitFor($language),
                'memory_limit' => $problem->getMemoryLimitFor($language),
                'test_case_count' => $problem->testCases()->count(),
            ],
            'language' => [
                'id' => $language->id,
                'extension' => $language->extension,
                'compile_command' => $language->compile_command,
                'run_command' => $language->run_command,
            ],
            'source' => [
                'filename' => $run->filename,
                'sha256' => $run->source_hash,
            ],
        ];
    }
}
