<?php

namespace App\Console\Commands;

use App\Jobs\JudgeRunJob;
use App\Models\Answer;
use App\Models\ContestLog;
use App\Models\Run;
use Illuminate\Console\Command;

/**
 * Watchdog for issue #45: the "Atrasado" (overdue) badge added to the judge
 * screen in #40 is purely visual -- nothing actually re-dispatches or
 * resolves a run that's been pending past its site's max_judge_wait_time.
 * If JudgeRunJob silently fails to enqueue, or a queue worker dies, an
 * overdue run just sits there forever with a red badge no one may notice.
 *
 * Re-dispatches a stuck run once; if it's *still* pending on the next run
 * of this command, gives up and marks it judged with a "CS" (contest
 * stopped / judge error) verdict rather than leaving it silently invisible
 * to the team forever.
 */
class ReconcileStuckRunsCommand extends Command
{
    protected $signature = 'runs:reconcile-stuck';

    protected $description = 'Re-dispatch or give up on runs stuck pending past their site\'s max_judge_wait_time';

    public function handle(): int
    {
        // Must match JudgeController::index()'s pending+judging scope for
        // the "Atrasado" badge, or a run stuck in 'judging' (a worker died
        // mid-judge, after AutoJudgeService::judge() already flipped the
        // status) shows the overdue badge forever without this watchdog
        // ever touching it.
        $stuckRuns = Run::whereIn('status', ['pending', 'judging'])
            ->with('site:id,max_judge_wait_time')
            ->get()
            ->filter(fn (Run $run) => $run->isOverdue());

        foreach ($stuckRuns as $run) {
            if ($run->reconcile_attempts >= 1) {
                $this->giveUp($run);
            } else {
                $run->increment('reconcile_attempts');
                JudgeRunJob::dispatch($run);
                $this->info("Run #{$run->run_number} (ID: {$run->id}) re-dispatched (attempt {$run->reconcile_attempts}).");
            }
        }

        $this->info(count($stuckRuns) . ' stuck run(s) processed.');

        return self::SUCCESS;
    }

    private function giveUp(Run $run): void
    {
        $answer = Answer::where('contest_id', $run->contest_id)->where('short_name', 'CS')->first();

        $run->update([
            'status' => 'judged',
            'answer_id' => $answer?->id,
            // Set like every other finalization path (AutoJudgeService::
            // updateRunWithResult()/handleJudgingError()) so judged_time
            // isn't left null on a row that says status = 'judged'.
            'judged_time' => $run->contest?->getContestTime() ?? 0,
            'auto_judge_result' => 'Reconciler: run stayed pending after a re-dispatch attempt, marked as judge error.',
        ]);

        // Deliberately NOT calling Score::updateScore() here, matching
        // AutoJudgeService::handleJudgingError()'s existing precedent: a CS
        // verdict caused by our own infrastructure failing to judge in time
        // is not the team's fault and shouldn't count against their
        // attempts for this problem.
        ContestLog::error($run->contest_id, "Run #{$run->run_number} auto-marked as judge error by the stuck-run reconciler", [
            'run_id' => $run->id,
        ]);

        $this->warn("Run #{$run->run_number} (ID: {$run->id}) gave up after a retry, marked as judge error.");
    }
}
