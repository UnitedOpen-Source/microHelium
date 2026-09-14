<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Site;
use Helium\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Issue #142 -- BOCA has src/fbkp.php, the button an organiser presses
 * before importing a problem package, before a mass rejudge, before
 * touching the freeze time. microHelium had the `backups` table and the
 * Backup model and nothing that ever wrote a row.
 *
 * One call produces one numbered ZIP holding:
 *
 *   database.sql    the whole database, via DatabaseDumper
 *   files/...       this contest's files under storage/app
 *                   (config/backup.php: contest_directories)
 *   manifest.json   what this archive is, for whoever opens it later
 *
 * Two scoping decisions worth stating, because the archive mixes them:
 *
 *   The dump is the WHOLE database, not one contest. Restoring a single
 *   contest out of a relational schema means reconstructing the order of
 *   twenty-odd foreign-keyed tables, and getting it subtly wrong is the
 *   failure mode a backup exists to prevent. The contest and site on the
 *   Backup row record what the operator was working on when they took it,
 *   which is what the numbering and the log entry are about -- not a filter
 *   on the contents.
 *
 *   The files ARE filtered to the contest, because they can be: every
 *   directory in that config list is partitioned by contest id. Including
 *   them at all is the point -- a Run row whose source_file no longer
 *   exists cannot be rejudged and cannot be shown to a team disputing a
 *   verdict. The database alone would restore the bookkeeping and lose the
 *   evidence.
 */
class BackupService
{
    public function __construct(private DatabaseDumper $dumper) {}

    /**
     * @param  string|null  $connection  Database connection to dump; defaults to the application's.
     *
     * @throws BackupFailedException
     */
    public function create(Contest $contest, Site $site, User $user, ?string $connection = null): Backup
    {
        $connection ??= (string) config('database.default');

        // Built under a temporary name first. The backup number is only
        // allocated once there is a complete archive to attach it to, so a
        // failed dump does not burn a number or leave an 'active' row
        // pointing at a file that was never finished.
        $workPath = $this->temporaryPath();

        try {
            $files = $this->contestFiles($contest);
            $this->buildArchive($workPath, $contest, $site, $user, $connection, $files);

            return DB::transaction(function () use ($contest, $site, $user, $connection, $workPath, $files) {
                // Same lock as run and task numbering: two operators
                // pressing the button at once must not compute the same
                // MAX(backup_number)+1 and collide on the unique index.
                Backup::withTrashed()
                    ->where('contest_id', $contest->id)
                    ->where('site_id', $site->id)
                    ->lockForUpdate()
                    ->get();

                $number = Backup::getNextBackupNumber($contest->id, $site->id);
                $filename = $this->filename($contest, $site, $number);
                $relativePath = trim((string) config('backup.directory'), '/')."/{$filename}";

                $disk = Storage::disk('local');
                $disk->makeDirectory(dirname($relativePath));

                // Streamed, not file_get_contents(): the archive holds every
                // submitted source file of the contest and there is no
                // reason to hold a copy of it in PHP's memory.
                $stream = fopen($workPath, 'r');
                $disk->writeStream($relativePath, $stream);
                fclose($stream);

                $backup = Backup::create([
                    'contest_id' => $contest->id,
                    'site_id' => $site->id,
                    'user_id' => $user->user_id,
                    'backup_number' => $number,
                    'filename' => $filename,
                    'file_path' => $relativePath,
                    'file_size' => $disk->size($relativePath),
                    'status' => 'active',
                ]);

                // Issue #88's screen is where an organiser reconstructs what
                // happened during the contest. "Who pressed backup, and
                // when" is exactly the kind of entry that turns a confusing
                // afternoon into an explainable one -- and the entry is what
                // tells them a restore point from 14:32 exists at all.
                ContestLog::log(
                    contestId: $contest->id,
                    type: 'info',
                    message: "Backup #{$number} created by {$user->username}",
                    siteId: $site->id,
                    userId: $user->user_id,
                    ipAddress: 'cli',
                    context: [
                        'backup_id' => $backup->id,
                        'backup_number' => $number,
                        'filename' => $filename,
                        'file_size' => $backup->file_size,
                        'connection' => $connection,
                        'files' => count($files),
                    ],
                );

                return $backup;
            });
        } catch (BackupFailedException $e) {
            // A failed backup must be visible on the same screen as a
            // successful one. Silence here is how an organiser ends up
            // believing they have a restore point they do not have.
            ContestLog::log(
                contestId: $contest->id,
                type: 'error',
                message: 'Backup failed: '.$e->getMessage(),
                siteId: $site->id,
                userId: $user->user_id,
                ipAddress: 'cli',
                context: ['connection' => $connection],
            );

            throw $e;
        } finally {
            @unlink($workPath);
        }
    }

    /**
     * @param  array<string, string>  $files  archive path => absolute path on disk
     */
    private function buildArchive(
        string $target,
        Contest $contest,
        Site $site,
        User $user,
        string $connection,
        array $files
    ): void {
        $dumpPath = $this->temporaryPath();

        try {
            $this->dumper->dump($connection, $dumpPath);

            $zip = new ZipArchive;

            // tempnam() already created the file and ZipArchive::open()
            // refuses to CREATE over an existing path; OVERWRITE is what
            // the webcast export had to do for the same reason.
            if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new BackupFailedException("Nao foi possivel criar o arquivo de backup em {$target}.");
            }

            $zip->addFile($dumpPath, 'database.sql');

            foreach ($files as $archivePath => $absolutePath) {
                $zip->addFile($absolutePath, $archivePath);
            }

            $zip->addFromString('manifest.json', json_encode([
                'generated_at' => now()->toIso8601String(),
                'contest' => ['id' => $contest->id, 'name' => $contest->name],
                'site' => ['id' => $site->id, 'name' => $site->name],
                'triggered_by' => ['user_id' => $user->user_id, 'username' => $user->username],
                'connection' => $connection,
                'driver' => config("database.connections.{$connection}.driver"),
                'database' => config("database.connections.{$connection}.database"),
                'file_count' => count($files),
                // Spelled out because the person reading this file is
                // probably doing so on a bad day, in a shell, without this
                // repository open next to them.
                'notes' => [
                    'database.sql is a dump of the whole database, not of this contest alone.',
                    'files/ holds only this contest subtree of storage/app.',
                    'Restore is manual: unzip, then feed database.sql to your database client.',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            if (! $zip->close()) {
                throw new BackupFailedException('Falha ao finalizar o arquivo de backup: '.$zip->getStatusString());
            }
        } finally {
            // Only after close(): ZipArchive reads the added files lazily,
            // at close() time, not at addFile() time.
            @unlink($dumpPath);
        }
    }

    /**
     * @return array<string, string> archive path => absolute path on disk
     */
    private function contestFiles(Contest $contest): array
    {
        $disk = Storage::disk('local');
        $files = [];

        foreach ((array) config('backup.contest_directories', []) as $directory) {
            $root = "{$directory}/{$contest->id}";

            foreach ($disk->allFiles($root) as $file) {
                $files["files/{$file}"] = $disk->path($file);
            }
        }

        return $files;
    }

    private function filename(Contest $contest, Site $site, int $number): string
    {
        // Contest and site in the name because backup_number is only unique
        // within a contest+site: two files called backup-7.zip sitting in
        // the same directory would be two different backups.
        return sprintf(
            'backup-c%d-s%d-%03d-%s.zip',
            $contest->id,
            $site->id,
            $number,
            now()->format('Ymd-His')
        );
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mhbkp_');

        if ($path === false) {
            throw new BackupFailedException('Nao foi possivel criar arquivo temporario para o backup.');
        }

        return $path;
    }
}
