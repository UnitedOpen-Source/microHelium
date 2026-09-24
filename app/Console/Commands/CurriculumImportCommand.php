<?php

namespace App\Console\Commands;

use App\Services\Curriculum\CurriculumImporter;
use App\Services\Curriculum\CurriculumImportException;
use Illuminate\Console\Command;

/**
 * Issue #396 -- `php artisan curriculum:import database/curricula/bncc-computacao-2022.csv`
 * (docs/specs/396-curriculos-oficiais.md).
 *
 * O texto oficial de um currículo muda de versão e não mora em migration:
 * entra por aqui, de um CSV versionado com um manifesto JSON ao lado. Rodar
 * de novo o mesmo arquivo não muda nada.
 */
class CurriculumImportCommand extends Command
{
    protected $signature = 'curriculum:import
        {file : CSV (code,stage,axis,text); o manifesto <mesmo-nome>.json fica ao lado}';

    protected $description = 'Importa (ou reimporta) um currículo oficial e suas habilidades a partir de CSV';

    public function handle(CurriculumImporter $importer): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) && is_file(base_path($path))) {
            $path = base_path($path);
        }

        try {
            $result = $importer->import($path);
        } catch (CurriculumImportException $e) {
            $this->error('Importação recusada; nada foi gravado.');
            foreach ($e->errors as $error) {
                $this->line('  - '.$error);
            }

            return self::FAILURE;
        }

        $framework = $result['framework'];
        $this->info("{$framework->name} ({$framework->slug}, versão {$framework->version})");
        $this->line("criadas: {$result['created']}, atualizadas: {$result['updated']}, inalteradas: {$result['unchanged']}");

        if ($result['missing'] !== []) {
            $this->warn(count($result['missing']).' habilidade(s) existem no banco e não estão no CSV; foram mantidas: '.implode(', ', $result['missing']));
        }

        return self::SUCCESS;
    }
}
