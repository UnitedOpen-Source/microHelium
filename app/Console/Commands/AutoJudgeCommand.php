<?php

namespace App\Console\Commands;

use App\Services\AutoJudgeService;
use App\Services\Judgehost\SandboxPreflight;
use App\Services\JudgeWorkQueue;
use Illuminate\Console\Command;

class AutoJudgeCommand extends Command
{
    protected $signature = 'autojudge:start
                            {--once : Process one run and exit}
                            {--sleep=10 : Seconds to sleep when no runs available}';

    protected $description = 'Start the auto-judge daemon to process pending submissions';

    public function __construct(
        protected AutoJudgeService $judgeService,
        protected JudgeWorkQueue $queue,
    ) {
        parent::__construct();
    }

    public function handle(SandboxPreflight $preflight): int
    {
        // Issue #282 -- recusa cedo, e em voz alta.
        //
        // Antes disto o daemon subia em qualquer maquina e o sintoma de uma
        // maquina incapaz de confinar era silencio: envios entravam e nada
        // saia. Pior, alguem podia "fazer funcionar" numa imagem que roda
        // como root e acabar executando codigo submetido sem confinamento
        // nenhum, achando que estava confinando.
        //
        // A autoridade continua sendo `judgehost:selftest`, que executa oito
        // casos de verdade. Isto e a parte barata da mesma pergunta, e quando
        // falha os oito casos falhariam tambem.
        if ($blocker = $preflight->blocker()) {
            $this->error('Esta maquina nao pode julgar.');
            $this->newLine();
            $this->line($blocker['reason']);
            $this->newLine();
            $this->line('Rode `php artisan judgehost:selftest` para o diagnostico completo.');

            return self::FAILURE;
        }

        $this->info('Auto-judge daemon started');

        $once = $this->option('once');
        $sleepTime = (int) $this->option('sleep');

        while (true) {
            // Issue #126 -- claimed under a row lock rather than merely
            // selected. Without this two workers on one machine pick the
            // same run and judge it twice, which made running more than one
            // of them a way to waste capacity rather than add it.
            $run = $this->queue->claimNextLocally();

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
