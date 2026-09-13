<?php

namespace App\Console\Commands;

use App\Models\Contest;
use App\Services\IcpcReportBuilder;
use Illuminate\Console\Command;

/**
 * Issue #89 -- the ICPC standings file from the command line.
 *
 * The web download exists too, but filing results is something an organiser
 * does once, often over ssh on the contest host after everyone has gone
 * home, and often into a file they then edit. A command is the shape that
 * fits.
 */
class IcpcReportCommand extends Command
{
    protected $signature = 'contest:icpc-report
                            {contest : Contest id}
                            {--output= : Write to this file instead of stdout}';

    protected $description = 'Export the ICPC standings file for a contest (usericpcid, placement, solved, total time, first solve)';

    public function handle(IcpcReportBuilder $builder): int
    {
        $contest = Contest::find($this->argument('contest'));

        if (! $contest) {
            $this->error('Contest '.$this->argument('contest').' nao encontrado.');

            return self::FAILURE;
        }

        if ($contest->is_practice) {
            // Issue #43: the practice contest is not an event and has no
            // standings to file.
            $this->error('O contest tecnico do Treino Livre nao tem classificacao para exportar.');

            return self::FAILURE;
        }

        $csv = $builder->csv($contest);
        $missing = $builder->teamsMissingIcpcId($contest);

        if ($output = $this->option('output')) {
            file_put_contents($output, $csv);
            $this->info('Relatorio escrito em '.$output.'.');
        } else {
            $this->line(rtrim($csv, "\n"));
        }

        if ($missing !== []) {
            // Warned, not refused: BOCA files the report with the column
            // blank, and an organiser may well be exporting before the ids
            // have been filled in.
            $this->warn(count($missing).' equipe(s) sem icpc_id preenchido, exportadas com o campo vazio:');
            foreach (array_slice($missing, 0, 10) as $team) {
                $this->warn('  - '.$team);
            }
            if (count($missing) > 10) {
                $this->warn('  ... e mais '.(count($missing) - 10).'.');
            }
        }

        return self::SUCCESS;
    }
}
