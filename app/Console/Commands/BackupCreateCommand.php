<?php

namespace App\Console\Commands;

use App\Models\Contest;
use App\Models\Site;
use App\Services\Backup\BackupFailedException;
use App\Services\Backup\BackupService;
use Helium\User;
use Illuminate\Console\Command;

/**
 * Issue #142 -- the restore point an organiser takes before importing a
 * problem package, before a mass rejudge, before moving the freeze time.
 * BOCA puts this behind a button (src/fbkp.php); here it is a command,
 * because during a contest the person doing it is already on the host over
 * ssh and because a command can be scheduled, while a button cannot.
 *
 * Restoring is deliberately NOT here (see #142): the archive is plain
 * unzip + a SQL file, usable with standard tools even when this application
 * is the thing that is broken, and that is what removes the risk. A restore
 * command that half-works during a live contest would add risk back.
 */
class BackupCreateCommand extends Command
{
    protected $signature = 'backup:create
                            {--contest= : Contest id (default: the only active competition)}
                            {--site= : Site id (default: the contest\'s only site)}
                            {--user= : user_id of whoever is triggering the backup}
                            {--connection= : Database connection to dump (default: the application\'s)}';

    protected $description = 'Create a numbered backup of the database and the contest files (issue #142)';

    public function handle(BackupService $backups): int
    {
        $contest = $this->resolveContest();

        if (! $contest) {
            return self::FAILURE;
        }

        $site = $this->resolveSite($contest);

        if (! $site) {
            return self::FAILURE;
        }

        $user = $this->resolveUser($contest);

        if (! $user) {
            return self::FAILURE;
        }

        $this->info("Gerando backup do contest '{$contest->name}' (sede '{$site->name}')...");

        try {
            $backup = $backups->create($contest, $site, $user, $this->option('connection') ?: null);
        } catch (BackupFailedException $e) {
            // The message is the operator's instruction, not a stack trace:
            // it names the missing tool, the empty dump or the truncated
            // file, and the failure is already on the ContestLog screen.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup #{$backup->backup_number} criado: {$backup->filename} ({$backup->getFileSizeFormatted()})");
        $this->line('Caminho: '.$backup->getFilePath());
        $this->comment('Para restaurar: unzip do arquivo e carregue database.sql no banco -- nao ha comando de restauracao.');

        return self::SUCCESS;
    }

    private function resolveContest(): ?Contest
    {
        if ($id = $this->option('contest')) {
            $contest = Contest::find($id);

            if (! $contest) {
                $this->error("Contest {$id} nao encontrado.");
            }

            return $contest;
        }

        // Issue #43: the Treino Livre practice contest is a technical row,
        // never what someone means by "the contest".
        $candidates = Contest::competition()->where('is_active', true)->get();

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $this->error($candidates->isEmpty()
            ? 'Nenhum contest ativo. Informe --contest=<id>.'
            : 'Mais de um contest ativo. Informe --contest=<id>.');

        foreach ($candidates as $candidate) {
            $this->line("  {$candidate->id}: {$candidate->name}");
        }

        return null;
    }

    private function resolveSite(Contest $contest): ?Site
    {
        if ($id = $this->option('site')) {
            $site = Site::where('contest_id', $contest->id)->find($id);

            if (! $site) {
                $this->error("Sede {$id} nao encontrada no contest {$contest->id}.");
            }

            return $site;
        }

        $sites = Site::where('contest_id', $contest->id)->get();

        if ($sites->count() === 1) {
            return $sites->first();
        }

        // backup_number is sequential per contest AND site, so which site
        // this backup belongs to is not a detail this command may guess.
        $this->error($sites->isEmpty()
            ? "Contest {$contest->id} nao tem nenhuma sede configurada."
            : 'Mais de uma sede neste contest. Informe --site=<id>.');

        foreach ($sites as $site) {
            $this->line("  {$site->id}: {$site->name}");
        }

        return null;
    }

    private function resolveUser(Contest $contest): ?User
    {
        if ($id = $this->option('user')) {
            $user = User::find($id);

            if (! $user) {
                $this->error("Usuario {$id} nao encontrado.");
            }

            return $user;
        }

        // Never guessed, even when there is exactly one admin: the whole
        // value of the ContestLog entry is that it names the person who
        // actually pressed the button. An attribution the system invented
        // is worse than no backup record at all.
        $this->error('Informe --user=<user_id> de quem esta disparando o backup.');

        $admins = User::where('contest_id', $contest->id)
            ->whereIn('user_type', ['admin', 'staff'])
            ->get();

        foreach ($admins as $admin) {
            $this->line("  {$admin->user_id}: {$admin->username} ({$admin->user_type})");
        }

        if ($admins->isEmpty()) {
            $this->line('  (nenhum admin/staff cadastrado neste contest)');
        }

        return null;
    }
}
