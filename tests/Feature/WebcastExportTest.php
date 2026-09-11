<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Services\BocaWebcastZipBuilder;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class WebcastExportTest extends TestCase
{
    use RefreshDatabase;

    private const FS = "\x1C";

    public function test_export_is_disabled_by_default_returning_503(): void
    {
        config(['webcast.export_enabled' => false]);
        $this->actingAs($this->createAdminUser());
        $contest = Contest::factory()->create();

        $this->getJson("/api/frontend/webcast/export?contest_id={$contest->id}")->assertStatus(503);
    }

    public function test_export_requires_admin_session(): void
    {
        config(['webcast.export_enabled' => true]);
        $contest = Contest::factory()->create();

        $this->getJson("/api/frontend/webcast/export?contest_id={$contest->id}")->assertStatus(401);

        $this->actingAs($this->createTestUser(['user_type' => 'team']));
        $this->getJson("/api/frontend/webcast/export?contest_id={$contest->id}")->assertStatus(403);
    }

    public function test_export_404s_for_unknown_contest_without_wrapping_html_in_a_zip(): void
    {
        config(['webcast.export_enabled' => true]);
        $this->actingAs($this->createAdminUser());

        $response = $this->getJson('/api/frontend/webcast/export?contest_id=999999');
        $response->assertStatus(404);
        $this->assertStringNotContainsStringIgnoringCase('PK', substr($response->getContent(), 0, 2));
    }

    public function test_export_headers_are_correct(): void
    {
        config(['webcast.export_enabled' => true]);
        $this->actingAs($this->createAdminUser());
        $contest = Contest::factory()->create(['duration' => 300, 'penalty' => 20]);

        $response = $this->get("/api/frontend/webcast/export?contest_id={$contest->id}");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $response->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.zip', $response->headers->get('Content-Disposition'));
    }

    public function test_zip_byte_format_round_trips_with_multisite_accents_and_result_codes(): void
    {
        config(['webcast.export_enabled' => true]);
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $contest = Contest::factory()->create([
            'name' => 'Maratona de Primavera',
            'duration' => 300,
            'penalty' => 20,
            'start_time' => now()->subMinutes(50),
        ]);
        $siteA = Site::factory()->create(['contest_id' => $contest->id, 'name' => 'Instituto Federal — São Paulo']);
        $siteB = Site::factory()->create(['contest_id' => $contest->id, 'name' => 'Universidade Cândido']);

        $teamA = User::factory()->create(['contest_id' => $contest->id, 'site_id' => $siteA->id, 'user_type' => 'team', 'fullname' => 'Equipe Ação']);
        $teamB = User::factory()->create(['contest_id' => $contest->id, 'site_id' => $siteB->id, 'user_type' => 'team', 'fullname' => 'Equipe Beta']);

        $problem = Problem::factory()->create(['contest_id' => $contest->id, 'short_name' => 'A', 'name' => 'Soma']);
        $language = Language::factory()->create(['contest_id' => $contest->id]);

        $accepted = Answer::factory()->create(['contest_id' => $contest->id, 'short_name' => 'AC', 'is_accepted' => true]);
        $compileError = Answer::factory()->create(['contest_id' => $contest->id, 'short_name' => 'CE', 'is_accepted' => false]);
        $wrong = Answer::factory()->create(['contest_id' => $contest->id, 'short_name' => 'WA', 'is_accepted' => false]);

        $runAccepted = Run::factory()->create([
            'contest_id' => $contest->id, 'site_id' => $siteA->id, 'user_id' => $teamA->user_id,
            'problem_id' => $problem->id, 'language_id' => $language->id, 'answer_id' => $accepted->id,
            'status' => 'judged', 'contest_time' => 605, // 10 minutes 5 seconds -> 10 minutes
        ]);
        $runCe = Run::factory()->create([
            'contest_id' => $contest->id, 'site_id' => $siteA->id, 'user_id' => $teamA->user_id,
            'problem_id' => $problem->id, 'language_id' => $language->id, 'answer_id' => $compileError->id,
            'status' => 'judged', 'contest_time' => 60,
        ]);
        $runWrong = Run::factory()->create([
            'contest_id' => $contest->id, 'site_id' => $siteB->id, 'user_id' => $teamB->user_id,
            'problem_id' => $problem->id, 'language_id' => $language->id, 'answer_id' => $wrong->id,
            'status' => 'judged', 'contest_time' => 120,
        ]);
        $runPending = Run::factory()->create([
            'contest_id' => $contest->id, 'site_id' => $siteB->id, 'user_id' => $teamB->user_id,
            'problem_id' => $problem->id, 'language_id' => $language->id, 'answer_id' => null,
            'status' => 'pending', 'contest_time' => 30,
        ]);

        $response = $this->get("/api/frontend/webcast/export?contest_id={$contest->id}");
        $response->assertOk();

        $zipPath = $this->writeZipToTempFile($response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);

        $this->assertSame(['version', 'contest', 'runs', 'time', 'icpc'], $this->entryNames($zip));

        $this->assertSame("1.0\n", $zip->getFromName('version'));
        $this->assertSame('', $zip->getFromName('icpc'));
        $this->assertSame("50\n", $zip->getFromName('time'));

        $contestFile = $zip->getFromName('contest');
        $lines = explode("\n", rtrim($contestFile, "\n"));

        $header = explode(self::FS, $lines[0]);
        $this->assertSame('Maratona de Primavera', $header[0]);
        $this->assertSame('300', $header[1]);
        $this->assertSame('20', $header[4]);

        $counts = explode(self::FS, $lines[1]);
        $this->assertSame('2', $counts[0]); // teams
        $this->assertSame('1', $counts[1]); // problems

        $teamLine = collect($lines)->first(fn ($l) => str_contains($l, 'Equipe Ação'));
        $this->assertNotNull($teamLine);
        $teamFields = explode(self::FS, $teamLine);
        $this->assertSame((string) $teamA->user_id, $teamFields[0]);
        $this->assertSame('Instituto Federal — São Paulo', $teamFields[1]);
        $this->assertSame('Equipe Ação', $teamFields[2]);

        $problemLine = collect($lines)->first(fn ($l) => str_contains($l, self::FS.'A'.self::FS));
        $this->assertNotNull($problemLine);

        $runsFile = $zip->getFromName('runs');
        $runLines = array_values(array_filter(explode("\n", $runsFile)));
        $this->assertCount(4, $runLines);

        $byId = [];
        foreach ($runLines as $line) {
            $fields = explode(self::FS, $line);
            $byId[$fields[0]] = $fields;
        }

        $this->assertSame('10', $byId[(string) $runAccepted->id][1]); // minutes
        $this->assertSame((string) $teamA->user_id, $byId[(string) $runAccepted->id][2]);
        $this->assertSame((string) $problem->id, $byId[(string) $runAccepted->id][3]);
        $this->assertSame('Y', $byId[(string) $runAccepted->id][4]);

        $this->assertSame('X', $byId[(string) $runCe->id][4]);
        $this->assertSame('N', $byId[(string) $runWrong->id][4]);
        $this->assertSame('?', $byId[(string) $runPending->id][4]);

        $zip->close();
        @unlink($zipPath);
    }

    public function test_zip_of_empty_contest_still_produces_valid_parseable_files(): void
    {
        config(['webcast.export_enabled' => true]);
        $this->actingAs($this->createAdminUser());
        $contest = Contest::factory()->create();

        $response = $this->get("/api/frontend/webcast/export?contest_id={$contest->id}");
        $response->assertOk();

        $zipPath = $this->writeZipToTempFile($response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertSame("1.0\n", $zip->getFromName('version'));
        $this->assertSame('', $zip->getFromName('runs'));
        $contestFile = $zip->getFromName('contest');
        $lines = explode("\n", rtrim($contestFile, "\n"));
        $counts = explode(self::FS, $lines[1]);
        $this->assertSame('0', $counts[0]);
        $this->assertSame('0', $counts[1]);
        $zip->close();
        @unlink($zipPath);
    }

    public function test_a_stray_non_team_run_is_excluded_but_does_not_break_the_export(): void
    {
        // routes/api.php's apiResource('runs', ...) lets any authenticated
        // user create a Run via the API, with no user_type restriction --
        // e.g. an admin debugging via /api/runs. That run belongs to no
        // exported team and must not crash the whole export for this
        // contest (see App\Services\BocaWebcastZipBuilder::runs()).
        config(['webcast.export_enabled' => true]);
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id]);

        Run::factory()->create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'user_id' => $admin->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'status' => 'pending',
        ]);

        $response = $this->get("/api/frontend/webcast/export?contest_id={$contest->id}");
        $response->assertOk();

        $zipPath = $this->writeZipToTempFile($response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertSame('', $zip->getFromName('runs'));
        $zip->close();
        @unlink($zipPath);
    }

    public function test_team_named_the_literal_string_zero_is_not_replaced_by_the_placeholder(): void
    {
        // "0" is falsy in PHP -- a `?:` fallback (rather than `??`) would
        // silently replace a team literally named "0" with the generated
        // placeholder. See App\Services\BocaWebcastZipBuilder::teams().
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $team = User::factory()->create(['contest_id' => $contest->id, 'site_id' => $site->id, 'user_type' => 'team', 'fullname' => '0']);

        $builder = app(BocaWebcastZipBuilder::class);
        $zipPath = $builder->build($contest);

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $contestFile = $zip->getFromName('contest');
        $lines = explode("\n", rtrim($contestFile, "\n"));
        // Line 0 is the header, line 1 is the team/problem counts (a
        // string like "1\x1C0" that -- if matched loosely -- can be
        // mistaken for a one-team export's own team line), line 2 is the
        // one team line this contest has.
        $fields = explode(self::FS, $lines[2]);
        $this->assertSame((string) $team->user_id, $fields[0]);
        $this->assertSame('0', $fields[2]);
        $zip->close();
        @unlink($zipPath);
    }

    public function test_sanitizes_fs_and_newline_characters_from_names(): void
    {
        $contest = Contest::factory()->create(['name' => "Nome\x1Ccom\nquebra"]);
        $builder = app(BocaWebcastZipBuilder::class);
        $zipPath = $builder->build($contest);

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $contestFile = $zip->getFromName('contest');
        $headerLine = explode("\n", $contestFile)[0];

        // The sanitized name must not introduce a spurious extra field --
        // FS and newline bytes from the raw name are replaced with a
        // space, so the header line still has exactly 5 FS-separated
        // fields instead of the corrupted 6 a raw FS byte would produce.
        $header = explode(self::FS, $headerLine);
        $this->assertCount(5, $header);
        $this->assertSame('Nome com quebra', $header[0]);

        $zip->close();
        @unlink($zipPath);
    }

    private function entryNames(ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        return $names;
    }

    private function writeZipToTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'webcast_test_').'.zip';
        file_put_contents($path, $contents);

        return $path;
    }
}
