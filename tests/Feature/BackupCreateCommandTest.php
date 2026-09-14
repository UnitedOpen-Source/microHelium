<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Site;
use App\Services\Backup\DatabaseDumper;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PDO;
use Tests\TestCase;
use ZipArchive;

/**
 * Issue #142 -- the `backups` table and the Backup model shipped with a
 * sequential-numbering routine and nothing that ever called it. These tests
 * are about the three things an organiser depends on when they press this
 * before a mass rejudge: the number is unique and sequential, the ContestLog
 * screen (#88) says who took it, and a backup that cannot be produced says
 * so out loud instead of leaving a plausible-looking file behind.
 *
 * The dump itself runs for real against the test database (sqlite, in
 * memory): DatabaseDumper's sqlite path is PHP over the open connection
 * precisely so that these are not mock assertions.
 */
class BackupCreateCommandTest extends TestCase
{
    use RefreshDatabase;

    private Contest $contest;

    private Site $site;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // The archive is written through the local disk, and so are the
        // contest files it collects; faking it keeps the suite from writing
        // into the real storage/app.
        Storage::fake('local');

        $this->contest = Contest::factory()->create(['name' => 'Regional 2026', 'is_active' => true]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id, 'name' => 'UFSCar']);
        $this->admin = User::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'username' => 'chief',
            'user_type' => 'admin',
        ]);
    }

    public function test_backups_are_numbered_sequentially_per_contest_and_site()
    {
        $this->runBackup()->assertExitCode(0);
        $this->runBackup()->assertExitCode(0);

        $this->assertSame([1, 2], Backup::orderBy('backup_number')->pluck('backup_number')->all());
    }

    public function test_numbering_is_scoped_to_the_site_not_the_contest()
    {
        $otherSite = Site::factory()->create(['contest_id' => $this->contest->id, 'name' => 'USP']);

        $this->runBackup()->assertExitCode(0);
        $this->runBackup(['--site' => $otherSite->id])->assertExitCode(0);

        $this->assertSame(1, Backup::where('site_id', $this->site->id)->value('backup_number'));
        $this->assertSame(1, Backup::where('site_id', $otherSite->id)->value('backup_number'));
    }

    /**
     * `backups` soft-deletes, and the unique index on
     * (contest_id, site_id, backup_number) counts trashed rows. Numbering
     * that ignored them would reuse a number and the insert would be
     * rejected by the database -- at the moment the operator wanted a
     * restore point.
     */
    public function test_a_deleted_backup_does_not_free_its_number_for_reuse()
    {
        $this->runBackup()->assertExitCode(0);
        Backup::first()->delete();

        $this->runBackup()->assertExitCode(0);

        $this->assertSame(2, Backup::latest('id')->value('backup_number'));
        $this->assertSame(1, Backup::onlyTrashed()->value('backup_number'));
    }

    public function test_it_records_who_triggered_the_backup_in_the_contest_log()
    {
        $this->runBackup()->assertExitCode(0);

        $log = ContestLog::where('contest_id', $this->contest->id)->latest('id')->first();

        $this->assertNotNull($log, 'a backup must leave a trace on the log screen');
        $this->assertSame('info', $log->type);
        $this->assertSame($this->admin->user_id, $log->user_id);
        $this->assertSame($this->site->id, $log->site_id);
        $this->assertStringContainsString('Backup #1', $log->message);
        $this->assertStringContainsString('chief', $log->message);
        $this->assertSame(Backup::first()->id, $log->context['backup_id']);
    }

    public function test_the_archive_holds_a_readable_dump_and_this_contest_files()
    {
        $other = Contest::factory()->create(['name' => 'Outra']);

        Storage::disk('local')->put("runs/{$this->contest->id}/7/main.cpp", 'int main(){}');
        Storage::disk('local')->put("problems/{$this->contest->id}/hello/problem.info", 'basename=hello');
        Storage::disk('local')->put("runs/{$other->id}/9/secret.cpp", 'not ours');

        $this->runBackup()->assertExitCode(0);

        $backup = Backup::first();
        $this->assertTrue(Storage::disk('local')->exists($backup->file_path));
        $this->assertSame(Storage::disk('local')->size($backup->file_path), $backup->file_size);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($backup->file_path)) === true);

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }

        $this->assertContains('database.sql', $entries);
        $this->assertContains("files/runs/{$this->contest->id}/7/main.cpp", $entries);
        $this->assertContains("files/problems/{$this->contest->id}/hello/problem.info", $entries);
        $this->assertNotContains("files/runs/{$other->id}/9/secret.cpp", $entries);

        $dump = $zip->getFromName('database.sql');
        $this->assertStringContainsString('CREATE TABLE "contests"', $dump);
        $this->assertStringContainsString(DatabaseDumper::COMPLETION_MARKER, $dump);

        // "Readable" is only worth asserting the way an organiser will find
        // out: feed the file to an empty database and read the contest back
        // out of it. Checking that the name appears *somewhere* in the text
        // passes happily on a dump whose INSERT statements no database will
        // accept -- which is exactly what the first version of this produced
        // (PDO::FETCH_BOTH put "0", "1", "2" in every column list).
        $restored = new PDO('sqlite::memory:');
        $restored->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $restored->exec($dump);

        $contest = $restored->query('SELECT name FROM contests')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Regional 2026', $contest['name']);
        $this->assertSame(
            'chief',
            $restored->query('SELECT username FROM users')->fetch(PDO::FETCH_ASSOC)['username']
        );

        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertSame($this->admin->user_id, $manifest['triggered_by']['user_id']);
        $zip->close();
    }

    /**
     * The production driver is MySQL and the dump is an external mysqldump.
     * A host without it must fail loudly: an empty or missing archive that
     * reported success is the failure this whole issue exists to prevent.
     */
    public function test_it_fails_with_a_clear_message_when_the_dump_tool_is_missing()
    {
        config([
            'database.connections.contest_mysql' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => '3306',
                'database' => 'microhelium',
                'username' => 'microhelium',
                'password' => 'secret',
            ],
            'backup.mysql.binary' => '/nonexistent/bin/mysqldump',
        ]);

        $this->runBackup(['--connection' => 'contest_mysql'])
            ->expectsOutputToContain('mysqldump nao encontrado')
            ->assertExitCode(1);

        $this->assertDatabaseCount('backups', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('backups'));

        // And the attempt is on the log screen, as an error: an organiser
        // must not have to read the terminal scrollback to find out that the
        // restore point they think they took does not exist.
        $log = ContestLog::latest('id')->first();
        $this->assertSame('error', $log->type);
        $this->assertStringContainsString('Backup failed', $log->message);
    }

    public function test_an_unsupported_driver_is_refused_rather_than_dumped_empty()
    {
        config(['database.connections.contest_pgsql' => ['driver' => 'pgsql', 'database' => 'x']]);

        $this->runBackup(['--connection' => 'contest_pgsql'])
            ->expectsOutputToContain("Backup nao suporta o driver 'pgsql'")
            ->assertExitCode(1);

        $this->assertDatabaseCount('backups', 0);
    }

    /**
     * The Backup row is an audit record. Attributing it to whoever the
     * system happened to find first would make that record a lie, so the
     * command refuses instead of guessing.
     */
    public function test_it_refuses_to_guess_who_triggered_the_backup()
    {
        $this->artisan('backup:create', ['--contest' => $this->contest->id, '--site' => $this->site->id])
            ->expectsOutputToContain('Informe --user=')
            ->assertExitCode(1);

        $this->assertDatabaseCount('backups', 0);
    }

    public function test_it_refuses_when_the_site_is_ambiguous()
    {
        Site::factory()->create(['contest_id' => $this->contest->id, 'name' => 'USP']);

        $this->artisan('backup:create', ['--contest' => $this->contest->id, '--user' => $this->admin->user_id])
            ->expectsOutputToContain('Mais de uma sede')
            ->assertExitCode(1);

        $this->assertDatabaseCount('backups', 0);
    }

    private function runBackup(array $options = [])
    {
        return $this->artisan('backup:create', array_merge([
            '--contest' => $this->contest->id,
            '--site' => $this->site->id,
            '--user' => $this->admin->user_id,
        ], $options));
    }
}
