<?php

namespace App\Console\Commands;

use App\Services\AutoJudgeService;
use App\Services\Judgehost\JudgehostAgent;
use App\Services\Judgehost\JudgehostClient;
use App\Services\Judgehost\WorkMaterialiser;
use Illuminate\Console\Command;
use Throwable;

/**
 * Issue #116 -- run the judge machine's agent.
 *
 * Meant to be the container's command on a judge host, the way
 * `autojudge:start` is on the single-machine setup. The difference is where
 * the work comes from: `autojudge:start` reads the database, this one asks
 * the server over HTTP and needs no database credentials at all.
 */
class JudgehostWorkCommand extends Command
{
    protected $signature = 'judgehost:work
        {--once : Fetch and judge at most one run, then stop}
        {--max-runs= : Stop after this many runs have been judged}';

    protected $description = 'Pull work from a microHelium server and judge it (issue #53/#116)';

    public function handle(): int
    {
        $client = JudgehostClient::fromConfig();

        if (! $client->isConfigured()) {
            $this->error('Configure JUDGEHOST_SERVER e JUDGEHOST_TOKEN antes de iniciar o agente.');
            $this->line('O token e emitido no servidor com: php artisan judgehost:create <nome>');

            return self::FAILURE;
        }

        $agent = new JudgehostAgent(
            $client,
            new WorkMaterialiser($client),
            app(AutoJudgeService::class),
            fn (string $level, string $message) => $this->say($level, $message),
        );

        $this->info("Agente apontando para {$client->server()}.");

        $once = (bool) $this->option('once');
        $maxRuns = $this->maxRuns();
        $judged = 0;
        $stop = false;

        try {
            $agent->register();
        } catch (Throwable $e) {
            $this->error("Nao foi possivel registrar no servidor: {$e->getMessage()}");

            return self::FAILURE;
        }

        // The loop is written here rather than calling $agent->run() so that
        // --once and --max-runs can end it: an operator needs a way to try
        // one run without leaving a daemon behind.
        //
        // --once bounds TICKS, not judged runs. Bounding judged runs would
        // make it spin on an empty queue forever, which is the opposite of
        // what someone reaches for it to do.
        while (($maxRuns === null || $judged < $maxRuns) && ! $stop) {
            try {
                if ($agent->tick()) {
                    $judged++;
                }

                $stop = $once;
            } catch (Throwable $e) {
                $this->say('error', "Falha ao falar com o servidor: {$e->getMessage()}");

                // A bounded invocation reports the failure instead of
                // retrying: --once that re-registered in a loop would be a
                // daemon, which is exactly what it exists not to be.
                if ($maxRuns !== null || $once) {
                    return self::FAILURE;
                }

                try {
                    $agent->register();
                } catch (Throwable $reregister) {
                    $this->say('error', "Falha ao registrar de novo: {$reregister->getMessage()}");
                }
            }
        }

        return self::SUCCESS;
    }

    private function maxRuns(): ?int
    {
        $option = $this->option('max-runs');

        if ($option === null || $option === '') {
            return null;
        }

        return max(1, (int) $option);
    }

    private function say(string $level, string $message): void
    {
        match ($level) {
            'error' => $this->error($message),
            'warn' => $this->warn($message),
            default => $this->line($message),
        };
    }
}
