<?php

namespace App\Jobs;

use App\Models\Run;
use App\Services\AutoJudgeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ShouldBeUnique (keyed on the run id, held for $uniqueFor) so
 * runs:reconcile-stuck's watchdog (issue #45) re-dispatching a run whose
 * original job just hasn't been picked up yet (queue backlog, not a lost
 * job) can't result in two workers judging the same run concurrently.
 */
class JudgeRunJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public int $backoff = 10;

    /** Matches $timeout -- the lock can't outlive the longest a legitimate judge attempt should take. */
    public int $uniqueFor = 300;

    public function __construct(
        public Run $run
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->run->id;
    }

    public function handle(AutoJudgeService $judgeService): void
    {
        // Skip if already judged
        if ($this->run->status === 'judged') {
            return;
        }

        $judgeService->judge($this->run);
    }

    public function failed(\Throwable $exception): void
    {
        $this->run->update([
            'status' => 'judged',
            'auto_judge_result' => 'Job failed: ' . $exception->getMessage(),
            'auto_judge_end' => now(),
        ]);
    }
}
