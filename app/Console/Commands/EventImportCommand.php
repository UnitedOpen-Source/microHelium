<?php

namespace App\Console\Commands;

use App\Services\EventImport\EventFileParser;
use App\Services\EventImport\EventImporter;
use App\Services\EventImport\EventImportPlan;
use App\Services\EventImport\ParsedEvent;
use App\Services\EventImport\PlannedChange;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Issue #147 -- provisions a whole event from one declarative file:
 * contest, sedes, linguagens and problemas.
 *
 * BOCA does this with src/system/importxml.php, whose content is
 * overwhelmingly about sites (40 mentions of `site`), and for good reason:
 * a regional has dozens of them, each with its own coordinator, its own
 * judging wait limit and possibly its own schedule. microHelium had no
 * equivalent -- setting up an edition meant creating the contest, then each
 * site, then each language, then each problem, through the admin screens,
 * every year, by hand. That is repeated work, and it is where a typo turns
 * into a site whose scoreboard freezes at the wrong minute.
 *
 * The shape is deliberately the same as `teams:import` (#141), because an
 * organiser should not have to learn two CLIs on contest day:
 *
 *  - DRY RUN IS THE DEFAULT. Without --apply nothing is written; the
 *    command prints every row it would create, change or leave alone, and
 *    for a change it prints the before and after of each field. --apply
 *    asks for confirmation unless --force is given, and a non-interactive
 *    --apply without --force declines rather than proceeds.
 *
 *  - ALL OR NOTHING. The plan is recomputed inside the transaction and the
 *    import is refused if anything at all is wrong. A half-applied event
 *    -- eleven of forty sites, the twelfth rejected by a unique index -- is
 *    worse than a refused one, because nobody can tell by looking which
 *    half landed.
 *
 *  - RE-RUNNABLE. See EventImporter for what identity means at each level.
 *    Running the same file twice changes nothing the second time; running
 *    an edited file applies exactly the edit, which is the point of keeping
 *    the file in git next to the problem packages.
 *
 * THE FILE IS THE STATE, NOT A PATCH. A field left out of an entry is set
 * to its default, not left as it is -- that is what makes the diff between
 * two editions readable, and it is why the preview exists. The one thing
 * the importer never does is delete: rows that exist in the contest and are
 * absent from the file are listed and left alone.
 */
class EventImportCommand extends Command
{
    protected $signature = 'event:import
                            {file : Arquivo YAML ou JSON que descreve a competicao}
                            {--contest= : Id do contest descrito pelo arquivo (padrao: casar pelo nome)}
                            {--format=auto : json, yaml ou auto}
                            {--apply : Grava as alteracoes (sem isto o comando apenas mostra o que faria)}
                            {--force : Nao pedir confirmacao interativa com --apply}';

    protected $description = 'Provision a contest, its sites, languages and problems from one declarative YAML/JSON file (issue #147)';

    public function handle(EventFileParser $parser, EventImporter $importer): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Arquivo nao encontrado ou sem permissao de leitura: {$path}");

            return self::FAILURE;
        }

        $contents = (string) file_get_contents($path);

        $format = (string) $this->option('format');
        if ($format === 'auto') {
            $format = (string) $parser->detectFormat($contents, $path);

            if ($format === '') {
                $this->error('Nao foi possivel reconhecer o formato do arquivo (esta vazio?).');
                $this->line('Use --format=yaml ou --format=json.');

                return self::FAILURE;
            }
        } elseif (! in_array($format, EventFileParser::FORMATS, true)) {
            $this->error('Formato invalido: '.$format.'. Use '.implode(', ', EventFileParser::FORMATS).' ou auto.');

            return self::FAILURE;
        }

        $contestId = $this->option('contest') === null ? null : (int) $this->option('contest');

        // Relative paths inside the file (problem packages) resolve against
        // the file's own directory, so an event repository can be cloned
        // anywhere and applied from any working directory.
        $parsed = $parser->parse($contents, $format, dirname(realpath($path)));
        $plan = $importer->plan($parsed, $contestId);

        $this->renderPlan($path, $format, $parsed, $plan);

        if (! $this->option('apply')) {
            $this->newLine();
            if ($plan->hasErrors()) {
                $this->comment('Simulacao: nada foi gravado. Corrija os erros acima antes de repetir com --apply.');
            } elseif (! $plan->hasWrites()) {
                $this->comment('Nada a fazer: a competicao ja esta como o arquivo descreve.');
            } else {
                $this->comment('Simulacao: nada foi gravado. Repita com --apply para aplicar.');
            }

            return $plan->hasErrors() ? self::FAILURE : self::SUCCESS;
        }

        if ($plan->hasErrors()) {
            $this->newLine();
            $this->error('Importacao recusada: o arquivo tem '.count($plan->errors).' erro(s). Nada foi gravado.');

            return self::FAILURE;
        }

        if (! $plan->hasWrites()) {
            $this->newLine();
            $this->info('Nada a fazer: a competicao ja esta como o arquivo descreve.');

            return self::SUCCESS;
        }

        $counts = $plan->counts();
        $this->newLine();
        if (! $this->option('force') && ! $this->confirm(
            sprintf(
                '%d registro(s) serao criados e %d alterado(s) a partir de %s. Confirma?',
                $counts[PlannedChange::CREATE],
                $counts[PlannedChange::UPDATE],
                basename($path),
            ),
            // Default "no": with --no-interaction this declines instead of
            // proceeding, so a scripted --apply has to say --force out loud.
            false
        )) {
            $this->line('Cancelado. Nada foi gravado.');

            return self::SUCCESS;
        }

        return $this->apply($importer, $parsed, $contestId);
    }

    private function apply(EventImporter $importer, ParsedEvent $parsed, ?int $contestId): int
    {
        try {
            $contest = DB::transaction(function () use ($importer, $parsed, $contestId) {
                // Planned again, inside the transaction, against rows that
                // cannot change under us from here on. The preview above was
                // computed before the confirmation prompt, and between the
                // two an administrator on the web side may well have created
                // the site that is about to be created here -- which would
                // otherwise surface as a unique-index abort halfway through.
                $plan = $importer->plan($parsed, $contestId);

                if ($plan->hasErrors()) {
                    throw new \RuntimeException(
                        'o estado do banco mudou desde a previa: '.$plan->errors[0]['location'].' -- '.$plan->errors[0]['message']
                    );
                }

                return $importer->apply($plan);
            });
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Importacao abortada: '.$e->getMessage());
            $this->line('Nada foi gravado -- a transacao inteira foi desfeita.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Competicao \"{$contest->name}\" (id {$contest->id}) importada com sucesso.");
        $this->line(sprintf(
            '%d sede(s), %d linguagem(ns) e %d problema(s) descritos no arquivo.',
            $contest->sites()->count(),
            $contest->languages()->count(),
            $contest->problems()->count(),
        ));

        if (! $contest->is_active) {
            $this->newLine();
            $this->comment('A competicao esta inativa (is_active: false). Ative-a quando for a hora.');
        }

        return self::SUCCESS;
    }

    private function renderPlan(string $path, string $format, ParsedEvent $parsed, EventImportPlan $plan): void
    {
        $this->info("Arquivo: {$path}");
        $this->line("Formato: {$format}");

        if ($plan->existingContest) {
            $this->line("Competicao: {$plan->existingContest->id} -- {$plan->existingContest->name} (ja existe)");
        } elseif (($parsed->contest['name'] ?? null) !== null) {
            $this->line("Competicao: {$parsed->contest['name']} (nova)");
        }

        $this->newLine();

        $rows = [];
        foreach ($plan->all() as $change) {
            $rows[] = [
                $change->kind,
                $change->location,
                mb_strimwidth($change->label, 0, 30, '...'),
                $change->status === PlannedChange::ERROR ? 'ERRO' : $change->status,
                mb_strimwidth($change->status === PlannedChange::ERROR ? $change->detail : trim($change->detail.' '.$change->changeSummary()), 0, 70, '...'),
            ];
        }

        if ($rows !== []) {
            $this->table(['Tipo', 'Onde', 'Item', 'Situacao', 'Detalhe'], $rows);
        }

        $counts = $plan->counts();
        $this->line(sprintf(
            'Resumo: %d a criar, %d a alterar, %d inalterado(s), %d erro(s) no arquivo.',
            $counts[PlannedChange::CREATE],
            $counts[PlannedChange::UPDATE],
            $counts[PlannedChange::UNCHANGED],
            count($plan->errors),
        ));

        foreach (['site' => 'sede(s)', 'language' => 'linguagem(ns)', 'problem' => 'problema(s)'] as $kind => $label) {
            if ($plan->orphans[$kind] !== []) {
                $this->newLine();
                $this->comment(count($plan->orphans[$kind])." {$label} nesta competicao fora do arquivo (nada sera removido): ".implode(', ', $plan->orphans[$kind]));
            }
        }

        if ($plan->warnings !== []) {
            $this->newLine();
            $this->warn('Avisos:');
            foreach ($plan->warnings as $warning) {
                $this->warn("  - {$warning['location']}: {$warning['message']}");
            }
        }

        if ($plan->errors !== []) {
            $this->newLine();
            $this->error('Erros (nada sera gravado enquanto existir algum):');
            foreach ($plan->errors as $error) {
                $this->error("  - {$error['location']}: {$error['message']}");
            }
        }
    }
}
