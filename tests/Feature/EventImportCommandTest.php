<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Site;
use App\Models\SiteJudgingRoute;
use App\Models\TestCase as ProblemTestCase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use ZipArchive;

/**
 * Issue #147 -- `event:import`, against the multi-site regional in
 * tests/Fixtures/event-import.
 *
 * What these tests are actually defending:
 *
 *  - nothing is written without --apply, because the file provisions a
 *    whole event and the preview is the only chance to catch a wrong
 *    freeze time before it is a wrong freeze time in production;
 *  - a second run of the same file changes nothing, because the file lives
 *    in git and gets re-applied whenever anything in it is edited;
 *  - an edited file applies exactly the edit, including the two cases that
 *    would otherwise hit a unique index (swapping problem letters) or
 *    silently duplicate (a soft-deleted site still owning its name);
 *  - a malformed file costs the whole import and reports every problem at
 *    once, each with the path to find it in the file.
 */
class EventImportCommandTest extends TestCase
{
    /**
     * ProblemPackageService moves packages into storage/app/problems, which
     * is real disk shared with the developer's own installation -- so the
     * cleanup is narrowed to the one basename these tests invent rather
     * than to the directory as a whole.
     */
    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/problems/*/somatorio')) ?: [] as $leftover) {
            $this->removeDirectory($leftover);
        }

        parent::tearDown();
    }

    private function removeDirectory(string $path): void
    {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path.'/'.$entry;
            is_dir($child) ? $this->removeDirectory($child) : @unlink($child);
        }

        @rmdir($path);
    }

    private function fixture(string $name): string
    {
        return __DIR__.'/../Fixtures/event-import/'.$name;
    }

    /**
     * Runs the importer and returns everything it printed.
     *
     * Assertions go through this rather than expectsOutputToContain() for
     * the reason TeamImportCommandTest documents: that helper cannot make
     * more than one match against output that follows a $this->table(), and
     * this command prints a table before every one of its summaries.
     *
     * @param  array<string, mixed>  $options
     */
    private function importOutput(string $file, array $options = []): string
    {
        Artisan::call('event:import', array_merge(['file' => $file], $options));

        return Artisan::output();
    }

    /**
     * The fixture, with some lines rewritten, saved where the command can
     * read it. Editing the file and re-running is the workflow this whole
     * command exists to support, so the tests do it literally.
     *
     * @param  array<string, string>  $replacements
     */
    private function editedFixture(array $replacements): string
    {
        $contents = (string) file_get_contents($this->fixture('regional.yml'));

        foreach ($replacements as $from => $to) {
            $this->assertStringContainsString($from, $contents, "a fixture nao contem \"{$from}\"; o teste ficaria vazio");
            $contents = str_replace($from, $to, $contents);
        }

        $path = sys_get_temp_dir().'/event-import-'.$this->uniqueSuffix().'.yml';
        file_put_contents($path, $contents);

        return $path;
    }

    private function importRegional(): string
    {
        return $this->importOutput($this->fixture('regional.yml'), ['--apply' => true, '--force' => true]);
    }

    // --------------------------------------------------------- dry run

    public function test_dry_run_is_the_default_and_writes_nothing()
    {
        $output = $this->importOutput($this->fixture('regional.yml'));

        $this->assertStringContainsString('UNIPAMPA Alegrete', $output);
        $this->assertStringContainsString('16 a criar', $output);
        $this->assertStringContainsString('Simulacao: nada foi gravado', $output);

        $this->assertSame(0, Contest::count());
        $this->assertSame(0, Site::count());
        $this->assertSame(0, Language::count());
        $this->assertSame(0, Problem::count());
    }

    public function test_apply_creates_the_whole_event()
    {
        $this->artisan('event:import', [
            'file' => $this->fixture('regional.yml'),
            '--apply' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $contest = Contest::firstOrFail();
        $this->assertSame('Maratona SBC 2026 -- Primeira Fase', $contest->name);
        $this->assertSame(300, $contest->duration);
        // Read raw: Contest::getFreezeTimeAttribute() shadows the column
        // and returns the instant the scoreboard freezes.
        $this->assertSame(60, (int) $contest->getRawOriginal('freeze_time'));
        $this->assertFalse($contest->is_active);
        $this->assertTrue($contest->is_public);
        $this->assertFalse($contest->is_practice);

        $this->assertSame(6, $contest->sites()->count());
        $this->assertSame(5, $contest->languages()->count());
        $this->assertSame(4, $contest->problems()->count());
    }

    /**
     * The failure the issue names out loud: "sede com fuso errado desloca o
     * horario de congelamento daquela sede". 13:00 in Brasilia is 16:00 UTC,
     * and the application timezone is UTC in this suite.
     */
    public function test_start_time_is_converted_from_the_offset_in_the_file_to_the_application_timezone()
    {
        $this->importRegional();

        $this->assertSame(
            '2026-09-19 16:00:00',
            Contest::firstOrFail()->start_time->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_new_contest_gets_the_default_answers()
    {
        $this->importRegional();

        $contest = Contest::firstOrFail();

        // Not a number pulled out of the air: the same list every other
        // contest-creating path seeds, or a judged run lands with a null
        // answer_id (tests/Feature/DefaultAnswersTest).
        $this->assertSame(
            collect(Answer::getDefaultAnswers())->pluck('short_name')->sort()->values()->all(),
            $contest->answers()->pluck('short_name')->sort()->values()->all(),
        );
    }

    public function test_site_fields_land_where_the_file_put_them()
    {
        $this->importRegional();

        $ufrgs = Site::where('name', 'UFRGS')->firstOrFail();
        $this->assertSame('Coordenacao UFRGS', $ufrgs->chief_judge_name);
        $this->assertSame('10.20.0.0/16', $ufrgs->ip_address);
        $this->assertSame(600, $ufrgs->max_judge_wait_time);
        $this->assertSame('all', $ufrgs->score_visibility);

        $unipampa = Site::where('name', 'UNIPAMPA Alegrete')->firstOrFail();
        $this->assertSame('own_site', $unipampa->score_visibility);
        $this->assertFalse($unipampa->auto_judge);
        $this->assertSame(1800, $unipampa->max_judge_wait_time);

        $remota = Site::where('name', 'Sede remota (contingencia)')->firstOrFail();
        $this->assertFalse($remota->is_active);
        $this->assertFalse($remota->permit_logins);
    }

    /**
     * judges_for names sites by name because the file cannot know ids.
     */
    public function test_judging_routes_are_resolved_by_site_name()
    {
        $this->importRegional();

        $host = Site::where('name', 'UFRGS')->firstOrFail();

        $sources = SiteJudgingRoute::where('host_site_id', $host->id)
            ->join('sites', 'sites.id', '=', 'site_judging_routes.source_site_id')
            ->pluck('sites.name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['UNIPAMPA Alegrete', 'URI Erechim'], $sources);
    }

    public function test_language_presets_come_from_the_builtin_catalog()
    {
        $this->importRegional();

        $cpp = Language::where('extension', 'cpp_gpp13')->firstOrFail();
        $catalog = collect(Language::getDefaultLanguages())->firstWhere('extension', 'cpp_gpp13');

        $this->assertSame($catalog['name'], $cpp->name);
        $this->assertSame($catalog['compile_command'], $cpp->compile_command);
        $this->assertSame($catalog['run_command'], $cpp->run_command);
        $this->assertTrue($cpp->is_active);

        // The file overrode is_active for this one.
        $this->assertFalse(Language::where('extension', 'kt')->firstOrFail()->is_active);
    }

    public function test_problem_sort_order_follows_the_order_in_the_file()
    {
        $this->importRegional();

        $this->assertSame(
            ['abrigo', 'bilhetes', 'corrida', 'dominos'],
            Problem::orderBy('sort_order')->pluck('basename')->all(),
        );
    }

    // ----------------------------------------------------- idempotency

    public function test_running_the_same_file_twice_creates_nothing_the_second_time()
    {
        $this->importRegional();

        $before = [
            'contests' => Contest::pluck('id')->all(),
            'sites' => Site::pluck('id')->all(),
            'languages' => Language::pluck('id')->all(),
            'problems' => Problem::pluck('id')->all(),
        ];

        $output = $this->importOutput($this->fixture('regional.yml'));

        $this->assertStringContainsString('0 a criar, 0 a alterar, 16 inalterado(s)', $output);
        $this->assertStringContainsString('Nada a fazer', $output);

        $this->assertSame($before['contests'], Contest::pluck('id')->all());
        $this->assertSame($before['sites'], Site::pluck('id')->all());
        $this->assertSame($before['languages'], Language::pluck('id')->all());
        $this->assertSame($before['problems'], Problem::pluck('id')->all());
    }

    /**
     * The same event described in the other supported format has to produce
     * the same plan -- otherwise "escolha YAML ou JSON" is a trap.
     */
    public function test_the_json_and_yaml_fixtures_describe_the_same_event()
    {
        $this->importRegional();

        $output = $this->importOutput($this->fixture('regional.json'));

        $this->assertStringContainsString('Formato: json', $output);
        $this->assertStringContainsString('0 a criar, 0 a alterar, 16 inalterado(s)', $output);
    }

    // ------------------------------------------------------------ edits

    public function test_an_edited_field_is_reported_with_its_before_and_after_and_then_applied()
    {
        $this->importRegional();

        $edited = $this->editedFixture([
            '  freeze_time: 60' => '  freeze_time: 45',
            'Coordenacao UFSC' => 'Prof. Marina Alves',
        ]);

        $preview = $this->importOutput($edited);
        $this->assertStringContainsString('freeze_time: 60 -> 45', $preview);
        $this->assertStringContainsString('chief_judge_name: Coordenacao UFSC -> Prof. Marina Alves', $preview);
        // Still a preview.
        $this->assertSame(60, (int) Contest::firstOrFail()->getRawOriginal('freeze_time'));

        $this->importOutput($edited, ['--apply' => true, '--force' => true]);

        $this->assertSame(45, (int) Contest::firstOrFail()->getRawOriginal('freeze_time'));
        $this->assertSame('Prof. Marina Alves', Site::where('name', 'UFSC')->firstOrFail()->chief_judge_name);
        $this->assertSame(6, Site::count(), 'editar uma sede nao pode criar outra');
    }

    /**
     * Reassigning the problem letters between editions is an ordinary edit
     * to the file and an immediate UNIQUE(contest_id, short_name) violation
     * if the rows are written one at a time -- see
     * EventImporter::parkSecondaryKeys().
     */
    public function test_problem_letters_can_be_swapped_in_a_single_run()
    {
        $this->importRegional();

        $edited = $this->editedFixture([
            "  - short_name: \"A\"\n    name: \"Abrigo de Animais\"" => "  - short_name: \"B\"\n    name: \"Abrigo de Animais\"",
            "  - short_name: \"B\"\n    name: \"Bilhetes da Rodoviaria\"" => "  - short_name: \"A\"\n    name: \"Bilhetes da Rodoviaria\"",
        ]);

        $this->artisan('event:import', ['file' => $edited, '--apply' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame('B', Problem::where('basename', 'abrigo')->firstOrFail()->short_name);
        $this->assertSame('A', Problem::where('basename', 'bilhetes')->firstOrFail()->short_name);
        $this->assertSame(4, Problem::count());
    }

    /**
     * Identity is the basename, not the letter: renaming the package means
     * a different problem, and the old one is reported rather than touched.
     */
    public function test_a_renamed_basename_creates_a_new_problem_and_reports_the_old_one()
    {
        $this->importRegional();

        $edited = $this->editedFixture(['basename: "abrigo"' => 'basename: "abrigo-2026"']);

        $output = $this->importOutput($edited);

        $this->assertStringContainsString('abrigo-2026', $output);
        $this->assertStringContainsString('fora do arquivo (nada sera removido): abrigo', $output);
        $this->assertNotNull(Problem::where('basename', 'abrigo')->first());
    }

    public function test_rows_absent_from_the_file_are_reported_and_never_deleted()
    {
        $this->importRegional();

        Site::create(['contest_id' => Contest::firstOrFail()->id, 'name' => 'Sede criada na mao']);

        $output = $this->importOutput($this->fixture('regional.yml'), ['--apply' => true, '--force' => true]);

        $this->assertStringContainsString('fora do arquivo (nada sera removido)', $output);
        $this->assertStringContainsString('Sede criada na mao', $output);
        $this->assertNotNull(Site::where('name', 'Sede criada na mao')->first());
    }

    /**
     * sites.name is UNIQUE(contest_id, name) with no deleted_at condition,
     * so a trashed site still owns its name. Creating "the same" site again
     * would be a driver-level duplicate-key abort; restoring is the only
     * outcome that leaves the contest looking like the file.
     */
    public function test_a_soft_deleted_site_is_restored_instead_of_duplicated()
    {
        $this->importRegional();

        $site = Site::where('name', 'URI Erechim')->firstOrFail();
        $site->delete();

        $output = $this->importOutput($this->fixture('regional.yml'), ['--apply' => true, '--force' => true]);

        $this->assertStringContainsString('restaura registro removido', $output);
        $this->assertSame(6, Site::where('contest_id', Contest::firstOrFail()->id)->count());
        $this->assertFalse(Site::withTrashed()->findOrFail($site->id)->trashed());
    }

    // --------------------------------------------------------- refusals

    public function test_a_malformed_file_reports_every_problem_with_its_location_and_writes_nothing()
    {
        $this->artisan('event:import', ['file' => $this->fixture('malformed.yml')])
            ->assertExitCode(1);

        $output = $this->importOutput($this->fixture('malformed.yml'));

        // One pass over the file, every problem at once -- not "fix this
        // one, re-run, find the next".
        $this->assertStringContainsString('contest.freze_time: campo desconhecido', $output);
        $this->assertStringContainsString('contest.penalty: esperado numero inteiro', $output);
        $this->assertStringContainsString('sites[0].name: campo obrigatorio ausente', $output);
        $this->assertStringContainsString('sites[1].score_visibility: valor invalido', $output);
        $this->assertStringContainsString('sites[1].judges_for: sede "Sede que nao existe"', $output);
        $this->assertStringContainsString('sites[2]: name repetido no proprio arquivo', $output);
        $this->assertStringContainsString('sites[3].ip_address', $output);
        $this->assertStringContainsString('sites[3].max_judge_wait_time: deve ser no minimo 60', $output);
        $this->assertStringContainsString('languages[1]: extension repetido no proprio arquivo', $output);
        $this->assertStringContainsString('languages[2].extension: o identificador aceita', $output);
        $this->assertStringContainsString('languages[3].preset: preset desconhecido', $output);
        $this->assertStringContainsString('problems[1]: short_name "A" repetido', $output);
        $this->assertStringContainsString('problems[2].basename', $output);
        $this->assertStringContainsString('problems[3].color_hex', $output);

        $this->assertSame(0, Contest::count());
    }

    public function test_a_malformed_file_is_refused_even_with_apply()
    {
        $this->artisan('event:import', [
            'file' => $this->fixture('malformed.yml'),
            '--apply' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, Contest::count());
        $this->assertSame(0, Site::count());
        $this->assertSame(0, Language::count());
        $this->assertSame(0, Problem::count());
    }

    /**
     * A file that half-parses must not half-apply either: the transaction
     * is all or nothing, so the sites listed before the broken problem are
     * not created.
     */
    public function test_nothing_from_a_refused_file_reaches_the_database()
    {
        $edited = $this->editedFixture(['basename: "dominos"' => 'basename: "../../etc/passwd"']);

        $this->artisan('event:import', ['file' => $edited, '--apply' => true, '--force' => true])
            ->assertExitCode(1);

        $this->assertSame(0, Contest::count());
        $this->assertSame(0, Site::count());
    }

    public function test_a_yaml_syntax_error_is_reported_with_its_line()
    {
        $path = sys_get_temp_dir().'/event-import-broken-'.$this->uniqueSuffix().'.yml';
        file_put_contents($path, "version: 1\ncontest:\n  name: \"X\"\n   description: torto\n");

        $output = $this->importOutput($path);

        $this->assertStringContainsString('YAML invalido', $output);
        $this->assertStringContainsString('linha 4', $output);
        $this->assertSame(0, Contest::count());
    }

    public function test_an_unreadable_file_is_refused_before_anything_else()
    {
        $this->artisan('event:import', ['file' => '/nao/existe/evento.yml'])
            ->expectsOutputToContain('Arquivo nao encontrado')
            ->assertExitCode(1);
    }

    public function test_a_missing_version_is_refused()
    {
        $path = sys_get_temp_dir().'/event-import-noversion-'.$this->uniqueSuffix().'.yml';
        file_put_contents($path, "contest:\n  name: \"Sem versao\"\nsites:\n  - name: \"Unica\"\n");

        $output = $this->importOutput($path);

        $this->assertStringContainsString('version: campo obrigatorio ausente', $output);
        $this->assertSame(0, Contest::count());
    }

    /**
     * Contest identity is the name, and the name has no unique index. Two
     * contests sharing it is a question the importer must not answer by
     * itself: picking the wrong one would rewrite last year's event.
     */
    public function test_an_ambiguous_contest_name_is_refused_and_asks_for_an_explicit_id()
    {
        Contest::factory()->create(['name' => 'Maratona SBC 2026 -- Primeira Fase']);
        Contest::factory()->create(['name' => 'Maratona SBC 2026 -- Primeira Fase']);

        $output = $this->importOutput($this->fixture('regional.yml'));

        $this->assertStringContainsString('Informe --contest=<id>', $output);
        $this->assertSame(0, Site::count());
    }

    public function test_an_explicit_contest_id_binds_the_file_to_that_contest()
    {
        $older = Contest::factory()->create(['name' => 'Maratona SBC 2026 -- Primeira Fase']);
        Contest::factory()->create(['name' => 'Maratona SBC 2026 -- Primeira Fase']);

        $this->artisan('event:import', [
            'file' => $this->fixture('regional.yml'),
            '--contest' => $older->id,
            '--apply' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(6, Site::where('contest_id', $older->id)->count());
        $this->assertSame(2, Contest::count(), 'nenhuma competicao nova foi criada');
    }

    /**
     * Issue #43: the practice contest is a technical row, not an event.
     */
    public function test_the_practice_contest_never_receives_an_import()
    {
        $practice = Contest::factory()->create(['name' => 'Treino Livre', 'is_practice' => true]);

        $output = $this->importOutput($this->fixture('regional.yml'), ['--contest' => $practice->id]);

        $this->assertStringContainsString('Treino Livre', $output);
        $this->assertSame(0, Site::count());
    }

    /**
     * A contest whose name matches an existing *practice* contest must not
     * be swallowed by it either -- the match is scoped by competition().
     */
    public function test_matching_by_name_ignores_the_practice_contest()
    {
        Contest::factory()->create(['name' => 'Maratona SBC 2026 -- Primeira Fase', 'is_practice' => true]);

        $this->artisan('event:import', [
            'file' => $this->fixture('regional.yml'),
            '--apply' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(2, Contest::count());
        $this->assertSame(6, Site::count());
        $this->assertSame(0, Site::where('contest_id', Contest::query()->practice()->firstOrFail()->id)->count());
    }

    // ---------------------------------------------------------- packages

    /**
     * `package:` delegates to ProblemPackageService -- the same code the
     * problem-import screen uses -- rather than reimplementing zip reading
     * and test-case registration.
     */
    public function test_a_problem_package_is_imported_through_problem_package_service()
    {
        $dir = $this->packageFixtureDirectory();

        $output = $this->importOutput($dir.'/evento.yml', ['--apply' => true, '--force' => true]);

        $this->assertStringContainsString('importada com sucesso', $output);

        $problem = Problem::where('basename', 'somatorio')->firstOrFail();
        // The event file wins over problem.info for everything it states.
        $this->assertSame('A', $problem->short_name);
        $this->assertSame('Somatorio Simples', $problem->name);
        $this->assertSame(2, $problem->time_limit);

        $this->assertSame(2, ProblemTestCase::where('problem_id', $problem->id)->count());
    }

    public function test_a_package_is_not_reimported_over_a_problem_that_already_exists()
    {
        $dir = $this->packageFixtureDirectory();

        $this->importOutput($dir.'/evento.yml', ['--apply' => true, '--force' => true]);
        $output = $this->importOutput($dir.'/evento.yml', ['--apply' => true, '--force' => true]);

        $this->assertStringContainsString('o pacote nao sera reimportado', $output);
        $this->assertSame(
            2,
            ProblemTestCase::where('problem_id', Problem::where('basename', 'somatorio')->firstOrFail()->id)->count(),
            'reimportar duplicaria os casos de teste',
        );
    }

    public function test_a_missing_package_is_reported_with_the_entry_that_names_it()
    {
        $dir = $this->packageFixtureDirectory();
        unlink($dir.'/packages/somatorio.zip');

        $output = $this->importOutput($dir.'/evento.yml');

        $this->assertStringContainsString('problems[0].package: pacote nao encontrado', $output);
        $this->assertSame(0, Contest::count());
    }

    /**
     * Builds a throwaway event directory -- event file plus a real zip --
     * at run time rather than committing a binary fixture whose contents
     * nobody can review in a diff.
     */
    private function packageFixtureDirectory(): string
    {
        $dir = sys_get_temp_dir().'/event-package-'.$this->uniqueSuffix();
        @mkdir($dir.'/packages', 0755, true);

        $zipPath = $dir.'/packages/somatorio.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('problem.info', "basename=somatorio\nfullname=Nome vindo do pacote\ntime_limit=9\n");
        $zip->addFromString('input/1', "1 2\n");
        $zip->addFromString('output/1', "3\n");
        $zip->addFromString('input/2', "10 20\n");
        $zip->addFromString('output/2', "30\n");
        $zip->close();

        file_put_contents($dir.'/evento.yml', <<<'YAML'
        version: 1
        contest:
          name: "Prova com pacote"
        sites:
          - name: "Sede unica"
        problems:
          - short_name: "A"
            name: "Somatorio Simples"
            basename: "somatorio"
            time_limit: 2
            package: "packages/somatorio.zip"
        YAML);

        return $dir;
    }
}
