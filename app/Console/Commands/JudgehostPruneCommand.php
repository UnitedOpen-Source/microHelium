<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Issue #186 -- bound what a judge machine keeps.
 *
 * WorkMaterialiser caches every test-case file it downloads, by digest, and
 * that cache is the reason a warm judgehost is worth having: a host judging
 * 200 submissions of one problem fetches its test data once. Nothing
 * removed it, ever -- no command, no schedule, no size limit.
 *
 * That matters here more than it would elsewhere, because the premise of
 * #53 is that the machine lives in another institution's rack. What
 * accumulated was the hidden input and output of every problem that machine
 * had ever judged, from every contest, indefinitely: exactly the material
 * #153 took out of the API because a competitor must not see it, left on
 * somebody else's disk long after the event, with nobody having decided
 * that it should stay.
 *
 * Runs ON THE JUDGE MACHINE, not on the server -- that is where the files
 * are. Safe to run while judging: it only removes files that have not been
 * used for the whole retention window, and a file the current run needs was
 * touched when that run materialised it.
 */
class JudgehostPruneCommand extends Command
{
    protected $signature = 'judgehost:prune
        {--days= : Remove cached test data unused for this many days (default: judgehost.agent.cache_retention_days)}
        {--dry-run : List what would be removed and remove nothing}';

    protected $description = 'Remove test data this judge machine has not used recently (issue #186)';

    public function handle(): int
    {
        $days = $this->days();

        if ($days === null) {
            $this->error('--days precisa ser um numero inteiro de dias maior que zero.');

            return self::FAILURE;
        }

        $root = storage_path('app/'.trim((string) config('judgehost.agent.workspace', 'judgehost'), '/').'/cache');

        if (! is_dir($root)) {
            $this->line('Nada em cache nesta maquina.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days)->getTimestamp();
        $dryRun = (bool) $this->option('dry-run');
        $removed = 0;
        $bytes = 0;
        $kept = 0;

        foreach ($this->cachedFiles($root) as $file) {
            // filemtime() is "last used" and not "downloaded", because
            // WorkMaterialiser touches a cache hit. Without that, a problem
            // judged throughout a contest would age out from the moment it
            // first arrived.
            if (@filemtime($file) > $cutoff) {
                $kept++;

                continue;
            }

            $size = (int) @filesize($file);

            if ($dryRun || @unlink($file)) {
                $removed++;
                $bytes += $size;
            }
        }

        if (! $dryRun) {
            $this->removeEmptyShards($root);
        }

        $this->line(sprintf(
            '%s %d arquivo(s), %s. Mantidos %d usados nos ultimos %d dia(s).',
            $dryRun ? 'Removeria' : 'Removidos',
            $removed,
            $this->humanBytes($bytes),
            $kept,
            $days,
        ));

        return self::SUCCESS;
    }

    private function days(): ?int
    {
        $option = $this->option('days');

        if ($option === null || $option === '') {
            return max(1, (int) config('judgehost.agent.cache_retention_days', 7));
        }

        // A typo like `--days=seven` must not silently become 0 and delete
        // the entire cache, which is what (int) alone would do.
        if (! is_numeric($option) || (int) $option < 1) {
            return null;
        }

        return (int) $option;
    }

    /**
     * @return list<string>
     */
    private function cachedFiles(string $root): array
    {
        $files = [];

        foreach (glob($root.'/*/*') ?: [] as $entry) {
            if (is_file($entry)) {
                $files[] = $entry;
            }
        }

        return $files;
    }

    /**
     * The cache is sharded by the first two characters of the digest. An
     * emptied shard is left behind otherwise, and 256 stray directories are
     * noise in the listing an operator reads when something is wrong.
     */
    private function removeEmptyShards(string $root): void
    {
        foreach (glob($root.'/*') ?: [] as $shard) {
            if (is_dir($shard) && (glob($shard.'/*') ?: []) === []) {
                @rmdir($shard);
            }
        }
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;

        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'GB') {
                return sprintf('%.1f %s', $value, $unit);
            }

            $value /= 1024;
        }

        return $bytes.' B';
    }
}
