<?php

namespace App\Services;

use App\Http\Controllers\Judgehost\WorkController;
use App\Models\Judgehost;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        return $this->claimFor($judgehost);
    }

    /**
     * Issue #126 -- the same atomic claim, for a worker on this machine.
     *
     * autojudge:start did not have one. getNextPendingRun() selects the
     * oldest pending run and returns it, and judge() flips the status
     * afterwards, so two workers asking in that window both get the same
     * run and both judge it. Measured, not supposed: two calls in a row,
     * with no judging in between, return the same id.
     *
     * That made "just run more local workers" -- the cheap alternative
     * docs/specs/53-distributed-judging.md wanted measured BEFORE any of
     * the distributed machinery was justified -- not actually a working
     * configuration. It is one now, which is what makes that comparison
     * possible at all.
     *
     * judgehost_id stays null: this run is being judged by this machine,
     * not leased to anyone. expireStaleLeases() therefore leaves it alone,
     * and runs:reconcile-stuck (#45) keeps covering a local worker that
     * dies mid-judge, exactly as before.
     */
    public function claimNextLocally(): ?Run
    {
        return $this->claimFor(null);
    }

    private function claimFor(?Judgehost $judgehost): ?Run
    {
        return DB::transaction(function () use ($judgehost) {
            // auto_judge can be overridden per language
            // (problem_language_limits), so the candidate set cannot be
            // narrowed in SQL alone -- the same reason
            // AutoJudgeService::getNextPendingRun() filters in PHP.
            $candidates = Run::query()
                ->where('status', 'pending')
                ->whereNull('judgehost_id')
                // Issue #125 -- a run every host has refused stops being
                // offered to hosts. Circulating it forever is the failure
                // mode this replaces: each machine takes it, returns it,
                // and the next one takes it, while the team sees `pending`
                // and nobody sees why.
                //
                // Only judgehosts are cut off, deliberately. The reasons a
                // remote machine gives back -- a language it lacks, a
                // problem package it could not fetch -- are mostly things
                // the server itself has, so the local worker stays a real
                // fallback rather than the run simply dying. If that fails
                // too, runs:reconcile-stuck (#45) is the backstop that
                // stops it being invisible.
                ->when($judgehost !== null, fn ($query) => $query->where(
                    'give_back_count', '<', WorkController::GIVE_BACK_ALERT_AFTER
                ))
                ->with(['problem.languageLimits', 'language'])
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            $run = $candidates->first(
                fn (Run $run) => $run->problem && $run->language
                    && $run->problem->isAutoJudgeEnabledFor($run->language)
                    // Issue #117 -- and this machine has to be able to run
                    // it. Filtered here rather than in SQL for the same
                    // reason the auto-judge check is: the candidate set is
                    // already loaded, and one PHP pass beats a join whose
                    // fallback case (a host that has declared nothing) is
                    // "everything matches".
                    && ($judgehost === null || $judgehost->canJudge($run->language->extension))
            );

            if (! $run) {
                return null;
            }

            $run->update([
                'status' => 'judging',
                'judgehost_id' => $judgehost?->id,
                'claimed_at' => now(),
                // Issue #123 -- the fencing token for this claim. Identity
                // has to reach "which claim", not stop at "which machine":
                // two processes of one judgehost present the same
                // credential, so without this a hung agent that is
                // restarted can report over the run its replacement is
                // judging.
                'claim_token' => $judgehost === null ? null : Str::random(48),
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
                // The claim is over, so its token stops being current. A
                // process still holding it is exactly what this refuses.
                'claim_token' => null,
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
                'claim_token' => null,
            ]);
    }

    /**
     * Issue #120 -- the custom scripts this problem uses for this language.
     *
     * Keyed by the hook, null where the problem does not override it. The
     * digest is what makes the agent's cache safe: scripts are per problem
     * and per language, so a host judging many runs of one problem fetches
     * each script once, and a re-imported package invalidates it by itself.
     *
     * @return array<string, array{sha256: string, bytes: int}|null>
     */
    public function packageManifest(Problem $problem, Language $language): array
    {
        $extension = (string) $language->extension;

        $paths = [
            'compile' => $problem->getCompileScriptPath($extension),
            'run' => $problem->getRunScriptPath($extension),
            'compare' => $problem->getCompareScriptPath($extension),
        ];

        return array_map(fn (string $path) => is_file($path) ? [
            'sha256' => hash_file('sha256', $path),
            'bytes' => filesize($path),
        ] : null, $paths);
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

        $leaseSeconds = (int) config('judgehost.lease_seconds', 600);

        return [
            'run_id' => $run->id,
            'run_number' => $run->run_number,
            'contest_id' => $run->contest_id,
            // Issue #123. The agent sends this back on every request that
            // names the run; a token from a claim that is over is refused.
            'claim_token' => $run->claim_token,
            // And when it stops being current on its own, so an agent can
            // tell a judging that overran from one that is still worth
            // reporting.
            'lease_expires_at' => optional($run->claimed_at)->copy()->addSeconds($leaseSeconds)?->toIso8601String(),
            'lease_seconds' => $leaseSeconds,
            'problem' => [
                'id' => $problem->id,
                'short_name' => $problem->short_name,
                'time_limit' => $problem->getTimeLimitFor($language),
                'memory_limit' => $problem->getMemoryLimitFor($language),
                'test_case_count' => $problem->testCases()->count(),
                // Issue #120. Three hooks in AutoJudgeService come out of the
                // problem package on disk -- compile, run and compare -- and
                // every one of them is applied behind a file_exists() that
                // falls through to the default when the file is not there. A
                // judgehost has no package, so without this it would compare
                // exact strings on a problem with a tolerance checker and
                // mark a correct submission WA, in silence and in favour of
                // the wrong answer.
                //
                // So the payload states which ones exist, and the agent must
                // fetch them before judging -- or give the run back.
                'package' => $this->packageManifest($problem, $language),
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
