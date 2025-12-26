<?php

namespace App\Console\Commands;

use App\Services\AutoJudgeService;
use Illuminate\Console\Command;

class AutoJudgeCommand extends Command
{
    protected $signature = 'autojudge:start
                            {--once : Process one run and exit}
                            {--sleep=10 : Seconds to sleep when no runs available}';

    protected $description = 'Start the auto-judge daemon to process pending submissions';

    public function __construct(
        protected AutoJudgeService $judgeService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Auto-judge daemon started');

        $once = $this->option('once');
        $sleepTime = (int) $this->option('sleep');

        while (true) {
            $run = $this->judgeService->getNextPendingRun();

            if ($run) {
                $this->info("Processing run #{$run->run_number} (ID: {$run->id})");

                try {
                    $this->judgeService->judge($run);
                    $this->info("Run #{$run->run_number} completed: {$run->fresh()->answer?->short_name}");
                } catch (\Exception $e) {
                    $this->error("Error processing run #{$run->run_number}: {$e->getMessage()}");
                }

                if ($once) {
                    break;
                }
            } else {
                if ($once) {
                    $this->info('No pending runs found');
                    break;
                }

                $this->info("No pending runs, sleeping for {$sleepTime} seconds...");
                sleep($sleepTime);
            }
        }

        return Command::SUCCESS;
    }
}
