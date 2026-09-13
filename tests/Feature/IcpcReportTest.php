<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Score;
use App\Models\Site;
use App\Services\IcpcReportBuilder;
use App\Services\Practice\PracticeContest;
use Helium\User;
use Tests\TestCase;

/**
 * Issue #89 -- the ICPC standings file, matching the column layout BOCA's
 * src/admin/report/icpc.php produces:
 *
 *     usericpcid, placement, solved, total time, first solve
 */
class IcpcReportTest extends TestCase
{
    private Contest $contest;

    private Problem $problemA;

    private Problem $problemB;

    private Answer $accepted;

    private Answer $wrong;

    private Language $language;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create([
            'start_time' => now()->subMinutes(120),
            'is_active' => true,
            'duration' => 300,
            'penalty' => 20,
        ]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problemA = Problem::factory()->create(['contest_id' => $this->contest->id, 'sort_order' => 1]);
        $this->problemB = Problem::factory()->create(['contest_id' => $this->contest->id, 'sort_order' => 2]);
        $this->language = Language::factory()->create(['contest_id' => $this->contest->id]);
        $this->accepted = Answer::factory()->create(['contest_id' => $this->contest->id, 'is_accepted' => true]);
        $this->wrong = Answer::factory()->create(['contest_id' => $this->contest->id, 'is_accepted' => false]);
    }

    private function team(string $name, ?string $icpcId): User
    {
        return User::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_type' => 'team',
            'fullname' => $name,
            'icpc_id' => $icpcId,
        ]);
    }

    private function solve(User $team, Problem $problem, int $atMinute, bool $accepted = true): void
    {
        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'language_id' => $this->language->id,
            'status' => 'judged',
            'answer_id' => $accepted ? $this->accepted->id : $this->wrong->id,
            'contest_time' => $atMinute * 60,
        ]);

        Score::updateScore($run);
    }

    public function test_the_csv_has_bocas_five_columns_in_standings_order(): void
    {
        $winner = $this->team('Equipe Alfa', 'ICPC-001');
        $runnerUp = $this->team('Equipe Beta', 'ICPC-002');

        $this->solve($winner, $this->problemA, 10);
        $this->solve($winner, $this->problemB, 30);
        $this->solve($runnerUp, $this->problemA, 20);

        $csv = app(IcpcReportBuilder::class)->csv($this->contest->fresh());
        $lines = array_values(array_filter(explode("\n", $csv)));

        $this->assertCount(2, $lines);

        [$id, $placement, $solved, $totalTime, $firstSolve] = explode(',', $lines[0]);
        $this->assertSame('ICPC-001', $id);
        $this->assertSame('1', $placement);
        $this->assertSame('2', $solved);
        // The earliest accepted problem, not the latest and not the total.
        $this->assertSame('10', $firstSolve);
        $this->assertGreaterThan(0, (int) $totalTime);

        $this->assertSame('ICPC-002', explode(',', $lines[1])[0]);
        $this->assertSame('2', explode(',', $lines[1])[1]);
    }

    public function test_a_team_that_solved_nothing_reports_a_first_solve_of_zero(): void
    {
        $team = $this->team('Equipe Zero', 'ICPC-009');
        $this->solve($team, $this->problemA, 15, accepted: false);

        $row = app(IcpcReportBuilder::class)->rows($this->contest->fresh())[0];

        $this->assertSame(0, $row['solved']);
        // BOCA writes 0 here rather than leaving the field out.
        $this->assertSame(0, $row['first_solve']);
    }

    public function test_a_comma_in_an_icpc_id_cannot_shift_the_columns(): void
    {
        $this->solve($this->team('Equipe Vírgula', 'A,B'), $this->problemA, 5);

        $line = trim(app(IcpcReportBuilder::class)->csv($this->contest->fresh()));

        $this->assertCount(5, explode(',', $line));
        $this->assertStringStartsWith('A B,', $line);
    }

    public function test_teams_without_an_icpc_id_are_listed_rather_than_silently_exported_blank(): void
    {
        $this->solve($this->team('Com id', 'ICPC-1'), $this->problemA, 5);
        $this->solve($this->team('Sem id', null), $this->problemB, 7);
        $this->solve($this->team('Vazio', '   '), $this->problemA, 9);

        $missing = app(IcpcReportBuilder::class)->teamsMissingIcpcId($this->contest->fresh());

        sort($missing);
        $this->assertSame(['Sem id', 'Vazio'], $missing);
    }

    // --- the download ---------------------------------------------------

    public function test_the_download_requires_an_admin(): void
    {
        $url = "/api/frontend/contests/{$this->contest->id}/icpc-report";

        $this->getJson($url)->assertUnauthorized();

        $this->actingAs($this->createTestUser(['user_type' => 'team']));
        $this->getJson($url)->assertForbidden();
    }

    public function test_the_download_returns_csv_as_an_attachment(): void
    {
        $this->solve($this->team('Equipe Alfa', 'ICPC-001'), $this->problemA, 10);

        $response = $this->actingAs($this->createAdminUser())
            ->get("/api/frontend/contests/{$this->contest->id}/icpc-report")
            ->assertOk();

        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename="icpc-contest-', $response->headers->get('Content-Disposition'));
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('X-Icpc-Teams-Missing-Id', '0');

        $this->assertStringStartsWith('ICPC-001,1,1,', $response->getContent());
    }

    public function test_the_practice_contest_has_no_standings_to_file(): void
    {
        $practice = app(PracticeContest::class)->contest();

        // Issue #43: it is not an event, and must not be reachable through
        // an event-shaped export either.
        $this->actingAs($this->createAdminUser())
            ->get("/api/frontend/contests/{$practice->id}/icpc-report")
            ->assertNotFound();
    }

    public function test_an_unknown_contest_is_a_404(): void
    {
        $this->actingAs($this->createAdminUser())
            ->get('/api/frontend/contests/999999/icpc-report')
            ->assertNotFound();
    }

    // --- the command ----------------------------------------------------

    public function test_the_command_prints_the_report_and_warns_about_missing_ids(): void
    {
        $this->solve($this->team('Com id', 'ICPC-1'), $this->problemA, 5);
        $this->solve($this->team('Sem id', null), $this->problemB, 7);

        $this->artisan('contest:icpc-report', ['contest' => $this->contest->id])
            ->expectsOutputToContain('ICPC-1,')
            ->expectsOutputToContain('1 equipe(s) sem icpc_id')
            ->assertSuccessful();
    }

    public function test_the_command_refuses_the_practice_contest(): void
    {
        $practice = app(PracticeContest::class)->contest();

        $this->artisan('contest:icpc-report', ['contest' => $practice->id])
            ->assertFailed();
    }

    public function test_the_command_writes_to_a_file_when_asked(): void
    {
        $this->solve($this->team('Com id', 'ICPC-1'), $this->problemA, 5);
        $path = sys_get_temp_dir().'/icpc-'.uniqid().'.csv';

        try {
            $this->artisan('contest:icpc-report', ['contest' => $this->contest->id, '--output' => $path])
                ->assertSuccessful();

            $this->assertStringStartsWith('ICPC-1,1,1,', (string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }
}
