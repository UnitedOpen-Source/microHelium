<?php

namespace App\Console\Commands;

use App\Models\Contest;
use App\Models\Site;
use App\Services\ManagedAccountProvisioner;
use App\Services\TeamImport\ParsedTeamRow;
use App\Services\TeamImport\TeamFileParser;
use App\Services\UsernameTakenException;
use Helium\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Issue #141 -- bulk import of the team file the ICPC website produces.
 *
 * A regional site registers its teams on the ICPC system and downloads the
 * list; until now the only way to get those 60 teams into microHelium was
 * 60 trips through the admin form, retyping every university name. BOCA has
 * read these files since 2012 (doc/import-user.txt) and this command reads
 * the same three formats.
 *
 * Three decisions worth knowing about before reading the code:
 *
 * 1. DRY RUN IS THE DEFAULT. Without --apply nothing is written; the
 *    command prints the exact table of accounts it would create and stops.
 *    The failure this is designed against is 60 wrong rows landing in a
 *    live contest an hour before it starts -- at which point the damage is
 *    not "delete some users", it is a scoreboard full of wrong team names.
 *    --apply additionally asks for confirmation unless --force is given,
 *    and a non-interactive --apply without --force declines rather than
 *    proceeds.
 *
 * 2. IDENTITY IS (contest_id, icpc_id). Re-running an import must not
 *    duplicate teams, so "the same team" has to mean something. The ICPC
 *    team id is the only stable identifier in these files: the team name
 *    changes when the coach edits it, the institution short name varies
 *    between exports, and the row order is not stable -- but the id is the
 *    same value microHelium already files back to the ICPC in its
 *    standings report (issue #89, users.icpc_id). Scoped to the contest
 *    because the same team id genuinely reappears in next year's contest
 *    as a different account. The BOCA users.txt format may omit
 *    usericpcid; those rows fall back to the username, which is globally
 *    unique in this schema.
 *
 *    A matched team is SKIPPED, never updated. An organiser who fixed a
 *    team name by hand after the first import would otherwise have it
 *    silently reverted by a re-run -- and a re-run is exactly what happens
 *    when the ICPC file is re-downloaded to pick up late registrations.
 *
 * 3. CREDENTIALS COME FROM ISSUE #47, NOT FROM BOCA. BOCA generates a
 *    6-digit password per team and prints it once. This command instead
 *    calls ManagedAccountProvisioner -- the same code path as the
 *    /backend/managed-accounts screen -- so imported teams are ordinary
 *    managed accounts: no usable password, disabled until activated,
 *    private profile, attributed to the admin named in --as. What gets
 *    printed once is the activation link. See the PR for why a second,
 *    parallel password scheme was not introduced.
 */
class TeamImportCommand extends Command
{
    protected $signature = 'teams:import
                            {file : Arquivo de equipes (PC2_Team.tab, Teams.tsv ou users.txt do BOCA)}
                            {--contest= : Id do contest que recebe as equipes}
                            {--site= : Id do site para todas as linhas (padrao: coluna de site do arquivo)}
                            {--as= : Usuario admin responsavel pelas contas (obrigatorio com --apply)}
                            {--format=auto : pc2tab, tsv, boca ou auto}
                            {--username-prefix= : Prefixo do nome de usuario de cada equipe}
                            {--activation-hours=72 : Validade dos links de ativacao, em horas}
                            {--credentials= : Grava os links de ativacao neste arquivo CSV}
                            {--apply : Cria as contas (sem isto o comando apenas mostra o que faria)}
                            {--force : Nao pedir confirmacao interativa com --apply}';

    protected $description = 'Import ICPC/BOCA team files (PC2_Team.tab, Teams.tsv, users.txt) as team accounts (issue #141)';

    public function handle(TeamFileParser $parser, ManagedAccountProvisioner $provisioner): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Arquivo nao encontrado ou sem permissao de leitura: {$path}");

            return self::FAILURE;
        }

        $contest = $this->resolveContest();
        if (! $contest instanceof Contest) {
            return self::FAILURE;
        }

        $activationHours = $this->resolveActivationHours();
        if ($activationHours === null) {
            return self::FAILURE;
        }

        $contents = (string) file_get_contents($path);

        $format = (string) $this->option('format');
        if ($format === 'auto') {
            $format = (string) $parser->detectFormat($contents, $path);

            if ($format === '') {
                $this->error('Nao foi possivel reconhecer o formato do arquivo.');
                $this->line('Use --format=pc2tab (9 colunas), --format=tsv (7 colunas) ou --format=boca (users.txt).');

                return self::FAILURE;
            }
        } elseif (! in_array($format, TeamFileParser::FORMATS, true)) {
            $this->error('Formato invalido: '.$format.'. Use '.implode(', ', TeamFileParser::FORMATS).' ou auto.');

            return self::FAILURE;
        }

        $parsed = $parser->parse($contents, $format);

        // Site ids are microHelium's own (sites.id), unrelated to the site
        // numbers written in an ICPC export. --site is the normal way in
        // (one file per site is how a regional actually distributes them);
        // without it the file's own column is treated as a site id, which
        // is what BOCA does and is right only when the ids happen to line
        // up. Either way the id is checked against this contest's sites
        // before anything is created.
        $sites = Site::query()->where('contest_id', $contest->id)->pluck('name', 'id');

        $forcedSiteId = null;
        if ($this->option('site') !== null) {
            $forcedSiteId = (int) $this->option('site');

            if (! $sites->has($forcedSiteId)) {
                $this->error("Site {$forcedSiteId} nao existe no contest {$contest->id} ({$contest->name}).");
                $this->line('Sites deste contest: '.($sites->isEmpty() ? '(nenhum)' : $sites->map(fn ($name, $id) => "{$id}={$name}")->implode(', ')));

                return self::FAILURE;
            }
        }

        $issues = $parsed->errors;
        $plan = $this->buildPlan($parsed->rows, $contest, $sites, $forcedSiteId, $issues);

        $this->renderPlan($path, $format, $contest, $plan, $issues, $parsed->rows);

        $toCreate = array_values(array_filter($plan, fn (array $item) => $item['status'] === 'criar'));

        if (! $this->option('apply')) {
            $this->newLine();
            if ($toCreate === []) {
                $this->comment('Nada a criar. Nenhuma conta foi tocada.');
            } else {
                $this->comment('Simulacao: nenhuma conta foi criada. Repita com --apply para criar de fato.');
            }

            return $issues === [] ? self::SUCCESS : self::FAILURE;
        }

        $admin = $this->resolveAdmin();
        if (! $admin instanceof User) {
            return self::FAILURE;
        }

        if ($toCreate === []) {
            $this->newLine();
            $this->info('Nada a criar -- todas as equipes do arquivo ja existem neste contest.');

            return $issues === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        if (! $this->option('force') && ! $this->confirm(
            count($toCreate)." conta(s) de equipe serao criadas no contest \"{$contest->name}\" por {$admin->username}. Confirma?",
            // Default "no": with --no-interaction this declines instead of
            // proceeding, so a scripted --apply has to say --force out loud.
            false
        )) {
            $this->line('Cancelado. Nenhuma conta foi criada.');

            return self::SUCCESS;
        }

        return $this->createAccounts($provisioner, $toCreate, $contest, $admin, $activationHours, $issues);
    }

    private function resolveContest(): ?Contest
    {
        if ($this->option('contest') === null) {
            $this->error('Informe --contest=<id>. A importacao precisa saber em qual contest as equipes entram.');

            return null;
        }

        $contest = Contest::find((int) $this->option('contest'));

        if (! $contest) {
            $this->error('Contest '.$this->option('contest').' nao encontrado.');

            return null;
        }

        if ($contest->is_practice) {
            // Issue #43: the technical practice contest is not an event and
            // has no roster to import into.
            $this->error('O contest tecnico do Treino Livre nao recebe importacao de equipes.');

            return null;
        }

        return $contest;
    }

    /**
     * --as is required for --apply and not merely nice to have:
     * managed_by is what puts these accounts on /backend/managed-accounts
     * (that screen lists `whereNotNull('managed_by')`). Importing with a
     * null owner would create 60 accounts that are invisible in the only
     * UI built to manage them.
     */
    private function resolveAdmin(): ?User
    {
        $username = trim((string) $this->option('as'));

        if ($username === '') {
            $this->error('Informe --as=<usuario admin>: as contas importadas ficam registradas sob esse responsavel.');

            return null;
        }

        $admin = User::query()->whereRaw('LOWER(TRIM(username)) = ?', [mb_strtolower($username)])->first();

        if (! $admin) {
            $this->error("Usuario '{$username}' nao encontrado.");

            return null;
        }

        if (! $admin->isAdmin()) {
            $this->error("Usuario '{$username}' nao e administrador.");

            return null;
        }

        return $admin;
    }

    private function resolveActivationHours(): ?int
    {
        $raw = (string) $this->option('activation-hours');

        if (! ctype_digit($raw) || (int) $raw < 1 || (int) $raw > 8760) {
            $this->error('--activation-hours deve ser um numero inteiro entre 1 e 8760.');

            return null;
        }

        return (int) $raw;
    }

    /**
     * Decides, for every parsed row, whether it would be created, already
     * exists, or cannot be created -- without writing anything. This is the
     * function the dry run exists to show.
     *
     * @param  list<ParsedTeamRow>  $rows
     * @param  Collection<int, string>  $sites
     * @param  list<array{line: int, message: string}>  $issues
     * @return list<array{row: ParsedTeamRow, username: string, site_id: ?int, status: string, detail: string}>
     */
    private function buildPlan(array $rows, Contest $contest, $sites, ?int $forcedSiteId, array &$issues): array
    {
        $prefix = (string) $this->option('username-prefix');

        $usernames = [];
        foreach ($rows as $row) {
            $usernames[$row->line] = $prefix.$row->username;
        }

        // Two batched lookups instead of two queries per row: a 60-team
        // file would otherwise issue 120 round trips just to render a
        // preview.
        $icpcIds = array_values(array_filter(array_map(fn (ParsedTeamRow $r) => $r->icpcId, $rows)));
        $existingByIcpc = $icpcIds === []
            ? collect()
            : User::query()
                ->where('contest_id', $contest->id)
                ->whereIn('icpc_id', $icpcIds)
                ->get()
                ->keyBy(fn (User $u) => (string) $u->icpc_id);

        $existingByUsername = $usernames === []
            ? collect()
            : User::query()
                ->whereIn(DB::raw('LOWER(TRIM(username))'), array_map(mb_strtolower(...), array_values($usernames)))
                ->get()
                ->keyBy(fn (User $u) => mb_strtolower(trim((string) $u->username)));

        $plan = [];
        $seenIdentity = [];
        $seenUsername = [];

        foreach ($rows as $row) {
            $username = $usernames[$row->line];
            $usernameKey = mb_strtolower($username);

            $entry = ['row' => $row, 'username' => $username, 'site_id' => null, 'status' => 'criar', 'detail' => ''];

            // Duplicates inside the file itself are caught before the
            // database is consulted: without this the second copy would
            // reach the unique index and blow up mid-import, after the
            // earlier rows had already been committed.
            if (isset($seenIdentity[$row->identity()])) {
                $entry['status'] = 'erro';
                $entry['detail'] = 'equipe repetida no proprio arquivo (ja aparece na linha '.$seenIdentity[$row->identity()].')';
            } elseif (isset($seenUsername[$usernameKey])) {
                $entry['status'] = 'erro';
                $entry['detail'] = "usuario '{$username}' repetido no proprio arquivo (linha ".$seenUsername[$usernameKey].')';
            } elseif ($row->icpcId !== null && $existingByIcpc->has($row->icpcId)) {
                $existing = $existingByIcpc->get($row->icpcId);
                $entry['status'] = 'ja existe';
                $entry['detail'] = "id ICPC {$row->icpcId} ja e a conta #{$existing->user_id} ({$existing->username})";
            } elseif ($existingByUsername->has($usernameKey)) {
                $existing = $existingByUsername->get($usernameKey);
                // Same login, different team: not idempotency, a genuine
                // clash. users.username is unique across all contests, so
                // this is also how a second contest reusing ICPC ids
                // surfaces -- the fix is --username-prefix.
                $entry['status'] = 'erro';
                $entry['detail'] = "usuario '{$username}' ja pertence a conta #{$existing->user_id}; use --username-prefix";
            } else {
                $siteId = $forcedSiteId ?? $row->siteNumber;

                if ($siteId === null) {
                    $entry['status'] = 'erro';
                    $entry['detail'] = 'sem site: o arquivo nao traz a coluna e --site nao foi informado';
                } elseif (! $sites->has($siteId)) {
                    $entry['status'] = 'erro';
                    $entry['detail'] = "site {$siteId} nao existe no contest {$contest->id}; use --site";
                } else {
                    $entry['site_id'] = $siteId;
                }
            }

            if ($entry['status'] === 'criar') {
                $seenIdentity[$row->identity()] = $row->line;
                $seenUsername[$usernameKey] = $row->line;
            }

            if ($entry['status'] === 'erro') {
                $issues[] = ['line' => $row->line, 'message' => $entry['detail'].'.'];
            }

            $plan[] = $entry;
        }

        // Parse failures and plan failures are reported together, in file
        // order, so the organiser reads one list top to bottom.
        usort($issues, fn (array $a, array $b) => $a['line'] <=> $b['line']);

        return $plan;
    }

    /**
     * @param  list<array{row: ParsedTeamRow, username: string, site_id: ?int, status: string, detail: string}>  $plan
     * @param  list<array{line: int, message: string}>  $issues
     * @param  list<ParsedTeamRow>  $rows
     */
    private function renderPlan(string $path, string $format, Contest $contest, array $plan, array $issues, array $rows): void
    {
        $this->info("Arquivo: {$path}");
        $this->line("Formato: {$format}   Contest: {$contest->id} -- {$contest->name}");
        $this->newLine();

        if ($plan !== []) {
            $this->table(
                ['Linha', 'ICPC', 'Usuario', 'Nome completo', 'Site', 'Situacao'],
                array_map(fn (array $item) => [
                    $item['row']->line,
                    $item['row']->icpcId ?? '-',
                    $item['username'],
                    // Truncated for the table only -- the full value is
                    // what gets written.
                    mb_strimwidth($item['row']->fullname, 0, 42, '...'),
                    $item['site_id'] ?? '-',
                    $item['status'] === 'erro' ? 'ERRO: '.$item['detail'] : $item['status'],
                ], $plan)
            );
        }

        $counts = array_count_values(array_column($plan, 'status'));

        $this->line(sprintf(
            'Resumo: %d a criar, %d ja existente(s), %d linha(s) com problema.',
            $counts['criar'] ?? 0,
            $counts['ja existe'] ?? 0,
            count($issues),
        ));

        $discarded = array_filter($rows, fn (ParsedTeamRow $r) => $r->passwordDiscarded);
        if ($discarded !== []) {
            $this->newLine();
            $this->warn(count($discarded).' linha(s) traziam userpassword no arquivo. As senhas foram ignoradas:');
            $this->warn('as equipes definem a propria senha pelo link de ativacao (issue #47).');
        }

        if ($issues !== []) {
            $this->newLine();
            $this->warn('Linhas que nao serao importadas:');
            foreach ($issues as $issue) {
                $this->warn($issue['line'] > 0 ? "  - linha {$issue['line']}: {$issue['message']}" : "  - {$issue['message']}");
            }
        }
    }

    /**
     * @param  list<array{row: ParsedTeamRow, username: string, site_id: ?int, status: string, detail: string}>  $toCreate
     * @param  list<array{line: int, message: string}>  $issues
     */
    private function createAccounts(
        ManagedAccountProvisioner $provisioner,
        array $toCreate,
        Contest $contest,
        User $admin,
        int $activationHours,
        array $issues,
    ): int {
        $created = [];
        $failed = [];

        foreach ($toCreate as $item) {
            $row = $item['row'];

            try {
                // Deliberately one transaction per team (inside
                // provisioner->create), not one around the whole file. A
                // single row that loses a username race at the unique
                // index must not roll back the 59 teams already imported
                // -- the organiser would have no way to tell which half of
                // the file landed, and a re-run is safe precisely because
                // of the identity rule above.
                [$user, $token] = $provisioner->create([
                    'fullname' => $row->fullname,
                    'username' => $item['username'],
                    'contest_id' => $contest->id,
                    'site_id' => $item['site_id'],
                    'description' => $row->description,
                    'icpc_id' => $row->icpcId,
                ], $admin->user_id, $activationHours);

                $created[] = [
                    'user' => $user,
                    'token' => $token,
                    'row' => $row,
                ];
            } catch (UsernameTakenException $e) {
                $failed[] = ['line' => $row->line, 'message' => $e->getMessage()];
            }
        }

        $this->newLine();
        $this->info(count($created).' conta(s) de equipe criada(s).');

        if ($created !== []) {
            $this->renderCredentials($created, $activationHours);
        }

        foreach ($failed as $failure) {
            $this->error("linha {$failure['line']}: {$failure['message']}");
        }

        return $issues === [] && $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<array{user: User, token: string, row: ParsedTeamRow}>  $created
     */
    private function renderCredentials(array $created, int $activationHours): void
    {
        $expiresAt = now()->addHours($activationHours);

        $this->newLine();
        $this->warn('ENTREGUE UM LINK A CADA EQUIPE. Eles aparecem uma unica vez e nao podem ser recuperados.');
        $this->newLine();

        $this->table(
            ['Usuario', 'Equipe', 'Link de ativacao'],
            array_map(fn (array $item) => [
                $item['user']->username,
                mb_strimwidth($item['user']->fullname, 0, 34, '...'),
                ManagedAccountProvisioner::activationPath($item['token']),
            ], $created)
        );

        $this->comment('Links validos por '.$activationHours.'h, ate '.$expiresAt->format('d/m/Y H:i').'. Cada um serve uma unica vez.');
        $this->comment('Prefixe com a URL do servidor, por exemplo http://10.0.0.1'.ManagedAccountProvisioner::activationPath('...'));

        if ($file = $this->option('credentials')) {
            // A terminal table of 60 long tokens is not something anyone
            // can reliably copy out; the CSV is what actually gets printed
            // onto the slips handed to teams.
            $handle = fopen($file, 'w');
            fputcsv($handle, ['usuario', 'equipe', 'site_id', 'link_ativacao', 'expira_em']);
            foreach ($created as $item) {
                fputcsv($handle, [
                    $item['user']->username,
                    $item['user']->fullname,
                    $item['user']->site_id,
                    ManagedAccountProvisioner::activationPath($item['token']),
                    $expiresAt->toIso8601String(),
                ]);
            }
            fclose($handle);

            $this->newLine();
            $this->info("Links gravados em {$file}. Trate o arquivo como segredo e apague depois de distribuir.");
        }
    }
}
