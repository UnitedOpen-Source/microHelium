<?php

namespace Tests\Feature;

use App\Models\AccountActivation;
use App\Models\Contest;
use App\Models\Site;
use Helium\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * Issue #141 -- `teams:import`, against the real fixture files in
 * tests/Fixtures/team-import (CRLF, accented Brazilian university names,
 * one malformed row per file).
 *
 * The three things these tests are actually defending:
 *  - nothing is written without --apply, because the failure that matters
 *    is 60 wrong rows landing in a live contest;
 *  - a second run creates nothing, because re-downloading the ICPC file to
 *    pick up late registrations is normal;
 *  - a malformed row costs that row and nothing else.
 */
class TeamImportCommandTest extends TestCase
{
    private Contest $contest;

    private Site $siteA;

    private Site $siteB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create(['name' => 'Regional 2026', 'is_active' => true]);
        $this->siteA = Site::factory()->create(['contest_id' => $this->contest->id, 'name' => 'UENP']);
        $this->siteB = Site::factory()->create(['contest_id' => $this->contest->id, 'name' => 'USP']);
        $this->admin = User::factory()->create([
            'username' => 'chief',
            'user_type' => 'admin',
            'contest_id' => $this->contest->id,
        ]);
    }

    private function fixture(string $name): string
    {
        return __DIR__.'/../Fixtures/team-import/'.$name;
    }

    /** Import regional.tab into one site, which is how a regional site actually receives its file. */
    /**
     * Runs the importer and returns everything it printed.
     *
     * Assertions go through this rather than expectsOutputToContain()
     * because that helper cannot make more than one match against output
     * that follows a $this->table() -- reproduced in isolation: a command
     * that renders a table and then prints a line passes one
     * expectsOutputToContain and fails the second, with no project code
     * involved. These tests want several matches on the same run.
     *
     * @param  array<string, mixed>  $extra
     */
    private function importRegionalOutput(array $extra = []): string
    {
        Artisan::call('teams:import', array_merge([
            'file' => $this->fixture('regional.tab'),
            '--contest' => $this->contest->id,
            '--site' => $this->siteA->id,
            '--as' => 'chief',
            '--apply' => true,
            '--force' => true,
        ], $extra));

        return Artisan::output();
    }

    private function importRegional(array $extra = []): PendingCommand
    {
        return $this->artisan('teams:import', array_merge([
            'file' => $this->fixture('regional.tab'),
            '--contest' => $this->contest->id,
            '--site' => $this->siteA->id,
            '--as' => 'chief',
            '--apply' => true,
            '--force' => true,
        ], $extra));
    }

    public function test_dry_run_is_the_default_and_writes_nothing()
    {
        $this->artisan('teams:import', [
            'file' => $this->fixture('regional.tab'),
            '--contest' => $this->contest->id,
            '--site' => $this->siteA->id,
        ])
            ->expectsOutputToContain('Garotos de Programa - UENP')
            ->expectsOutputToContain('Simulacao: nenhuma conta foi criada')
            // Exit 1 because the fixture contains a malformed row; a clean
            // file exits 0 (see the Teams.tsv test below).
            ->assertExitCode(1);

        $this->assertSame(0, User::where('contest_id', $this->contest->id)->where('user_type', 'team')->count());
    }

    public function test_apply_creates_team_accounts_with_contest_site_and_accented_names_intact()
    {
        $this->importRegional()->assertExitCode(1); // malformed row on line 4

        $teams = User::where('contest_id', $this->contest->id)->where('user_type', 'team')->get();

        $this->assertCount(4, $teams, 'the four well-formed rows, the malformed one skipped');

        $team = $teams->firstWhere('username', '1783');
        $this->assertNotNull($team);
        $this->assertSame('Garotos de Programa - UENP', $team->fullname);
        $this->assertSame('Universidade Estadual do Norte do Paraná', $team->description);
        $this->assertSame('1783', $team->icpc_id);
        $this->assertSame($this->contest->id, $team->contest_id);
        $this->assertSame($this->siteA->id, $team->site_id);

        // Accents survive the whole round trip through the database, not
        // just the parser.
        $this->assertSame(
            'Física Computacional - IFSC-USP',
            $teams->firstWhere('username', '1892')->fullname
        );
    }

    public function test_imported_accounts_are_managed_accounts_exactly_as_issue_47_creates_them()
    {
        $this->importRegional();

        $team = User::where('username', '1783')->firstOrFail();

        // No usable password, disabled until the team activates, private,
        // attributed to the importing admin -- the same state
        // ManagedAccountsController::store() produces.
        $this->assertFalse((bool) $team->is_enabled);
        $this->assertSame('private', $team->profile_visibility);
        $this->assertSame($this->admin->user_id, $team->managed_by);
        $this->assertNotNull($team->managed_at);
        $this->assertFalse(Hash::check('', $team->password));

        // One single-use activation token each, hashed at rest.
        $activation = AccountActivation::where('user_id', $team->user_id)->firstOrFail();
        $this->assertNull($activation->used_at);
        $this->assertTrue($activation->isValid());
    }

    public function test_running_twice_creates_nothing_the_second_time()
    {
        $this->importRegional()->run();

        $firstPass = User::where('contest_id', $this->contest->id)->where('user_type', 'team')->pluck('user_id')->sort()->values();
        $this->assertCount(4, $firstPass);

        $output = $this->importRegionalOutput();

        $this->assertStringContainsString('ja existe', $output);
        $this->assertStringContainsString('Nada a criar', $output);

        $secondPass = User::where('contest_id', $this->contest->id)->where('user_type', 'team')->pluck('user_id')->sort()->values();

        $this->assertEquals($firstPass->all(), $secondPass->all(), 'no new rows and no rows replaced');
        // And no extra activation tokens minted for accounts that already
        // have one.
        $this->assertSame(4, AccountActivation::count());
    }

    public function test_a_team_renamed_by_hand_after_the_first_import_is_not_reverted_by_a_re_run()
    {
        $this->importRegional()->run();

        User::where('username', '1783')->update(['fullname' => 'Nome corrigido pela organizacao']);

        $this->importRegional()->run();

        $this->assertSame('Nome corrigido pela organizacao', User::where('username', '1783')->value('fullname'));
    }

    public function test_the_same_team_id_in_another_contest_is_a_different_team()
    {
        $this->importRegional()->run();

        $other = Contest::factory()->create(['name' => 'Regional 2027']);
        $otherSite = Site::factory()->create(['contest_id' => $other->id, 'name' => 'UENP']);

        // Identity is (contest_id, icpc_id), so this is genuinely new --
        // but users.username is globally unique, which is exactly what
        // --username-prefix exists for. Without it the run reports the
        // clash instead of silently mangling logins.
        $this->artisan('teams:import', [
            'file' => $this->fixture('regional.tab'),
            '--contest' => $other->id,
            '--site' => $otherSite->id,
            '--as' => 'chief',
            '--apply' => true,
            '--force' => true,
        ])->expectsOutputToContain('use --username-prefix')->run();

        $this->assertSame(0, User::where('contest_id', $other->id)->where('user_type', 'team')->count());

        $this->artisan('teams:import', [
            'file' => $this->fixture('regional.tab'),
            '--contest' => $other->id,
            '--site' => $otherSite->id,
            '--as' => 'chief',
            '--username-prefix' => 'r27-',
            '--apply' => true,
            '--force' => true,
        ])->run();

        $this->assertSame(4, User::where('contest_id', $other->id)->where('user_type', 'team')->count());
        $this->assertNotNull(User::where('username', 'r27-1783')->first());
    }

    public function test_malformed_rows_are_reported_with_their_line_number_and_cost_only_themselves()
    {
        $output = $this->importRegionalOutput();

        // The operator has to be able to find the bad row in the file.
        $this->assertStringContainsString('linha 4', $output);
        $this->assertStringContainsString('esperava 9 colunas', $output);

        // One unparseable row must not cost the rows around it.
        $this->assertNotNull(User::where('username', '1892')->first(), 'the row before the bad one landed');
        $this->assertNotNull(User::where('username', '1065')->first(), 'the row after the bad one landed');
        $this->assertSame(4, User::where('contest_id', $this->contest->id)->where('user_type', 'team')->count());
    }

    public function test_teams_tsv_uses_the_site_column_when_no_site_option_is_given()
    {
        // Sites are addressed by microHelium's own ids, so line them up
        // with what the fixture's site column says (1 and 2).
        $one = Site::factory()->create(['contest_id' => $this->contest->id, 'id' => 901, 'name' => 'EACH']);
        $two = Site::factory()->create(['contest_id' => $this->contest->id, 'id' => 902, 'name' => 'UFRN']);

        // Rewrite the fixture's site numbers onto the real ids for this
        // test rather than hardcoding ids into the shipped sample.
        $path = tempnam(sys_get_temp_dir(), 'tsv').'.tsv';
        file_put_contents($path, str_replace(
            ["\tnull\t1488\t1\t", "\tnull\t1487\t2\t", "\tnull\t1483\t1\t"],
            '',
            (string) file_get_contents($this->fixture('Teams.tsv'))
        ));
        // The replace above is a no-op guard; write the real mapping.
        file_put_contents($path, preg_replace(
            ['/^null\t(\d+)\t1\t/m', '/^null\t(\d+)\t2\t/m'],
            ["null\t\$1\t{$one->id}\t", "null\t\$1\t{$two->id}\t"],
            (string) file_get_contents($this->fixture('Teams.tsv'))
        ));

        $this->artisan('teams:import', [
            'file' => $path,
            '--contest' => $this->contest->id,
            '--as' => 'chief',
            '--apply' => true,
            '--force' => true,
        ])->assertExitCode(0); // Teams.tsv has no malformed row

        unlink($path);

        $this->assertSame(901, User::where('username', '1488')->value('site_id'));
        $this->assertSame(902, User::where('username', '1487')->value('site_id'));
        $this->assertSame(
            'Multi-thread Simultâneo (MTS) - EACH-USP',
            User::where('username', '1487')->value('fullname')
        );
    }

    public function test_boca_users_file_imports_only_teams_and_discards_its_plaintext_passwords()
    {
        $this->artisan('teams:import', [
            'file' => $this->fixture('users.txt'),
            '--contest' => $this->contest->id,
            '--site' => $this->siteA->id,
            '--as' => 'chief',
            '--apply' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('As senhas foram ignoradas')
            ->expectsOutputToContain("usertype='judge'")
            ->assertExitCode(1);

        $this->assertSame(2, User::where('contest_id', $this->contest->id)->where('user_type', 'team')->count());
        $this->assertSame(0, User::where('username', 'judge1')->count());

        // The password the file supplied must not be usable.
        $team = User::where('username', 'equipe-tres')->firstOrFail();
        $this->assertFalse(Hash::check('aBcDe', $team->password));
        $this->assertSame('Equipe Três', $team->fullname);
    }

    public function test_an_activation_link_from_the_import_actually_activates_the_account()
    {
        // One import, writing the credentials file an organiser prints onto
        // the slips handed to teams, and then the token off that very file
        // is used to activate the account that same run created.
        //
        // The first version of this test imported into a SECOND contest and
        // then overwrote the first account's token_hash so the assertion
        // would match -- which verified nothing except that the test could
        // edit the database.
        $csv = tempnam(sys_get_temp_dir(), 'creds').'.csv';

        $this->importRegionalOutput(['--credentials' => $csv]);

        $rows = array_map('str_getcsv', file($csv, FILE_IGNORE_NEW_LINES));
        unlink($csv);

        $row = collect($rows)->firstWhere(0, '1783');
        $this->assertNotNull($row, 'the credentials CSV must list every created team');

        $token = str_replace('/activate/', '', $row[3]);

        $team = User::where('username', '1783')->firstOrFail();
        $activation = AccountActivation::where('user_id', $team->user_id)->firstOrFail();

        // The command prints the raw token; the database keeps only its
        // hash. This is the link between the two.
        $this->assertSame($activation->token_hash, hash('sha256', $token));

        $this->post('/activate/'.$token, [
            'password' => 'senha-da-equipe',
            'password_confirmation' => 'senha-da-equipe',
        ])->assertRedirect('/home');

        $this->assertTrue((bool) User::where('username', '1783')->value('is_enabled'));

        // Single use: the same link must not work twice.
        $this->assertNotNull(AccountActivation::find($activation->id)->used_at);
    }

    public function test_it_refuses_a_site_that_does_not_belong_to_the_contest()
    {
        $elsewhere = Contest::factory()->create();
        $foreignSite = Site::factory()->create(['contest_id' => $elsewhere->id]);

        $this->artisan('teams:import', [
            'file' => $this->fixture('regional.tab'),
            '--contest' => $this->contest->id,
            '--site' => $foreignSite->id,
            '--as' => 'chief',
            '--apply' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('nao existe no contest')
            ->assertExitCode(1);

        $this->assertSame(0, User::where('contest_id', $this->contest->id)->where('user_type', 'team')->count());
    }

    public function test_apply_requires_an_admin_to_own_the_accounts()
    {
        $this->artisan('teams:import', [
            'file' => $this->fixture('regional.tab'),
            '--contest' => $this->contest->id,
            '--site' => $this->siteA->id,
            '--apply' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('Informe --as=')
            ->assertExitCode(1);

        $this->assertSame(0, User::where('contest_id', $this->contest->id)->where('user_type', 'team')->count());
    }

    public function test_a_non_admin_may_not_own_imported_accounts()
    {
        User::factory()->create(['username' => 'staffer', 'user_type' => 'staff']);

        $this->artisan('teams:import', [
            'file' => $this->fixture('regional.tab'),
            '--contest' => $this->contest->id,
            '--site' => $this->siteA->id,
            '--as' => 'staffer',
            '--apply' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('nao e administrador')
            ->assertExitCode(1);
    }

    public function test_the_practice_contest_does_not_receive_a_roster()
    {
        $practice = Contest::factory()->create(['is_practice' => true]);

        $this->artisan('teams:import', [
            'file' => $this->fixture('regional.tab'),
            '--contest' => $practice->id,
        ])
            ->expectsOutputToContain('Treino Livre')
            ->assertExitCode(1);
    }

    public function test_a_missing_file_says_so_instead_of_failing_obscurely()
    {
        $this->artisan('teams:import', [
            'file' => '/nao/existe/equipes.tab',
            '--contest' => $this->contest->id,
        ])
            ->expectsOutputToContain('Arquivo nao encontrado')
            ->assertExitCode(1);
    }

    public function test_apply_without_force_asks_before_creating_anything()
    {
        $this->artisan('teams:import', [
            'file' => $this->fixture('regional.tab'),
            '--contest' => $this->contest->id,
            '--site' => $this->siteA->id,
            '--as' => 'chief',
            '--apply' => true,
        ])
            ->expectsConfirmation(
                '4 conta(s) de equipe serao criadas no contest "Regional 2026" por chief. Confirma?',
                'no'
            )
            ->expectsOutputToContain('Cancelado')
            ->assertExitCode(0);

        $this->assertSame(0, User::where('contest_id', $this->contest->id)->where('user_type', 'team')->count());
    }
}
