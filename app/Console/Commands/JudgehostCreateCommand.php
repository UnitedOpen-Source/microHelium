<?php

namespace App\Console\Commands;

use App\Models\Judgehost;
use Illuminate\Console\Command;

/**
 * Issue #53 -- issue a credential for one judge machine.
 *
 * The token is printed once and never stored in the clear, so there is no
 * way to recover it: a lost token means issuing a new credential and
 * disabling the old one.
 */
class JudgehostCreateCommand extends Command
{
    protected $signature = 'judgehost:create {name : A label for the machine, e.g. judge-ufscar-01}';

    protected $description = 'Issue a credential for a judge machine (issue #53)';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));

        if ($name === '' || mb_strlen($name) > 80) {
            $this->error('Informe um nome de 1 a 80 caracteres.');

            return self::FAILURE;
        }

        if (Judgehost::where('name', $name)->exists()) {
            $this->error("Ja existe um judgehost chamado '{$name}'.");

            return self::FAILURE;
        }

        [$judgehost, $token] = Judgehost::issue($name);

        $this->info("Judgehost '{$judgehost->name}' criado (id {$judgehost->id}).");
        $this->newLine();
        $this->line('Token (aparece uma unica vez):');
        $this->line($token);
        $this->newLine();
        $this->comment('Guarde agora. Nao ha como recuperar depois -- so emitir outro e desabilitar este.');

        return self::SUCCESS;
    }
}
