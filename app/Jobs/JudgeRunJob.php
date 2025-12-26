<?php

namespace App\Jobs;

use App\Models\Run;
use App\Services\AutoJudgeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class JudgeRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public int $backoff = 10;

    public function __construct(
        public Run $run
    ) {}

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
