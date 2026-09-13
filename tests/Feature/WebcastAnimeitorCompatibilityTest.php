<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Services\BocaWebcastZipBuilder;
use Helium\User;
use Tests\TestCase;
use ZipArchive;

/**
 * Issue #44's "Gate de integracao" (docs/specs/44-webcast.md): the export
 * had never been read by the consumer it exists for, because the repository
 * the issue linked returned 404.
 *
 * The consumer is wuerges/maratona-animeitor-rust. This test is a faithful
 * port of its loader, pinned at commit
 * 555ba636e39da5585768218a1ac164666672ac0c:
 *
 *   server/service/src/webcast.rs   load_data_from_url_maybe
 *   server/service/src/dataio.rs    ContestFile / RunTuple / Team from_string
 *   server/data/src/lib.rs          Letter::from_str, ALPHABET
 *
 * Every rule asserted below is one that parser enforces with `?`, which
 * means violating it rejects the whole file rather than degrading one row.
 * A port is not the binary itself -- the PR description records the run
 * against the real crate -- but it is what keeps a future change to the
 * builder from silently breaking the import again.
 */
class WebcastAnimeitorCompatibilityTest extends TestCase
{
    private const FS = "\x1C";

    /** data/src/lib.rs: static ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZ" */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public function test_the_generated_zip_satisfies_every_rule_the_consumer_enforces(): void
    {
        $zip = $this->buildFixtureZip();

        // webcast.rs reads exactly these three by name. `version` and `icpc`
        // are never opened, so they may exist but cannot be relied on.
        foreach (['time', 'contest', 'runs'] as $name) {
            $this->assertNotFalse($zip->getFromName($name), "the consumer opens `{$name}` by name");
        }

        $contest = $this->assertParsesAsContestFile($zip->getFromName('contest'));
        $this->assertParsesAsRunsFile($zip->getFromName('runs'), $contest);
        $this->assertParsesAsTimeFile($zip->getFromName('time'));

        $zip->close();
    }

    /**
     * webcast.rs:
     *   let time_data: i64 = read_from_zip(&mut zip, "time")?.parse()?;
     *
     * No trim anywhere in that chain, and Rust's i64 parse accepts only an
     * optional sign followed by digits -- "50\n" is an error.
     */
    private function assertParsesAsTimeFile(string $contents): void
    {
        $this->assertMatchesRegularExpression(
            '/\A[+-]?\d+\z/',
            $contents,
            '`time` must parse as a bare i64: no trailing newline, no spaces'
        );
    }

    /**
     * dataio.rs ContestFile::from_string.
     *
     * @return array{teams: list<string>, problems: int}
     */
    private function assertParsesAsContestFile(string $contents): array
    {
        // Rust's str::lines() drops a single trailing newline and never
        // yields a phantom final empty line, which PHP's explode does.
        $lines = explode("\n", rtrim($contents, "\n"));

        $this->assertGreaterThanOrEqual(3, count($lines), 'contest needs a name, params and counts line');

        // Line 1: the contest name, taken whole. A stray FS would not even
        // be an error here -- it would silently become part of the name.
        $this->assertStringNotContainsString(self::FS, $lines[0]);

        // Line 2: exactly four params, each an i64.
        $params = explode(self::FS, $lines[1]);
        $this->assertCount(4, $params, 'failed parsing contest_params: the consumer requires exactly 4');
        foreach ($params as $index => $param) {
            $this->assertMatchesRegularExpression('/\A[+-]?\d+\z/', $param, "contest param {$index} must be an integer");
        }

        // Line 3: exactly two counts, each a usize.
        $counts = explode(self::FS, $lines[2]);
        $this->assertCount(2, $counts, 'failed parsing team params: the consumer requires exactly 2');
        $this->assertMatchesRegularExpression('/\A\d+\z/', $counts[0]);
        $this->assertMatchesRegularExpression('/\A\d+\z/', $counts[1]);

        $teamCount = (int) $counts[0];
        $problemCount = (int) $counts[1];

        // Then exactly number_teams lines, each three FS fields. The loop
        // is `lines.next().ok_or(...)?`, so a file that promises more teams
        // than it lists fails outright.
        $this->assertGreaterThanOrEqual(3 + $teamCount, count($lines), 'contest promises more teams than it lists');

        $logins = [];
        for ($i = 0; $i < $teamCount; $i++) {
            $team = explode(self::FS, $lines[3 + $i]);
            $this->assertCount(3, $team, 'failed parsing Team: the consumer requires exactly 3 fields');
            $logins[] = $team[0];
        }

        return ['teams' => $logins, 'problems' => $problemCount];
    }

    /**
     * dataio.rs read_runs + RunTuple::from_string.
     *
     * @param  array{teams: list<string>, problems: int}  $contest
     */
    private function assertParsesAsRunsFile(string $contents, array $contest): void
    {
        $letters = $this->problemLetters($contest['problems']);

        foreach (explode("\n", rtrim($contents, "\n")) as $line) {
            if ($line === '') {
                continue;
            }

            $fields = explode(self::FS, $line);
            $this->assertCount(5, $fields, 'failed parsing RunTuple: the consumer requires exactly 5 fields');

            $this->assertMatchesRegularExpression('/\A[+-]?\d+\z/', $fields[0], 'run id must be an i64');
            $this->assertMatchesRegularExpression('/\A[+-]?\d+\z/', $fields[1], 'run time must be an i64');

            // team_login is a String, so anything parses -- but a run whose
            // login is not one of the exported teams has no team to attach
            // to on the consumer side.
            $this->assertContains($fields[2], $contest['teams'], 'run references a team not in this export');

            // Letter::from_str: non-empty and every char in A-Z. A numeric
            // problem id is a BadLetter, and read_runs maps with `?`, so one
            // such line rejects the entire file.
            $this->assertNotSame('', $fields[3]);
            $this->assertSame(
                '',
                trim($fields[3], self::ALPHABET),
                'run problem must be a Letter (A-Z only), not a numeric id'
            );
            $this->assertContains(
                $fields[3],
                $letters,
                'the consumer generates problem letters from number_problems; anything outside that range is UnmatchedProblem'
            );

            // from_string_answer: exactly these four, anything else is
            // Error::InvalidAnswer.
            $this->assertContains($fields[4], ['Y', 'N', 'X', '?'], 'unknown answer code');
        }
    }

    /** data/src/lib.rs problem_letters(): combinations with replacement, lengths 1..3. */
    private function problemLetters(int $count): array
    {
        $alphabet = str_split(self::ALPHABET);
        $letters = $alphabet;

        for ($i = 0; $i < 26 && count($letters) < $count; $i++) {
            for ($j = $i; $j < 26 && count($letters) < $count; $j++) {
                $letters[] = $alphabet[$i].$alphabet[$j];
            }
        }

        return array_slice($letters, 0, max($count, 26));
    }

    private function buildFixtureZip(): ZipArchive
    {
        $contest = Contest::factory()->create([
            'name' => 'Maratona de Primavera',
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'start_time' => now()->subMinutes(50),
        ]);
        $site = Site::factory()->create(['contest_id' => $contest->id, 'name' => 'Instituto Federal — São Paulo']);

        $problems = collect([1, 2, 3])->map(fn ($order) => Problem::factory()->create([
            'contest_id' => $contest->id,
            // Deliberately NOT A/B/C: the consumer never sees these labels,
            // so the export must not depend on them being letters at all.
            'short_name' => 'P'.$order,
            'sort_order' => $order,
        ]));

        $accepted = Answer::create(['contest_id' => $contest->id, 'name' => 'Accepted', 'short_name' => 'AC', 'is_accepted' => true]);
        $compileError = Answer::create(['contest_id' => $contest->id, 'name' => 'Compilation Error', 'short_name' => 'CE', 'is_accepted' => false]);
        $wrong = Answer::create(['contest_id' => $contest->id, 'name' => 'Wrong Answer', 'short_name' => 'WA', 'is_accepted' => false]);

        $runNumber = 0;
        foreach (['Equipe Ação', 'Time Zero'] as $index => $name) {
            $team = User::factory()->create([
                'contest_id' => $contest->id,
                'site_id' => $site->id,
                'user_type' => 'team',
                'fullname' => $name,
            ]);

            foreach ([$accepted, $compileError, $wrong, null] as $offset => $answer) {
                Run::factory()->create([
                    'contest_id' => $contest->id,
                    'site_id' => $site->id,
                    'user_id' => $team->user_id,
                    'problem_id' => $problems[$offset % 3]->id,
                    'answer_id' => $answer?->id,
                    'status' => $answer ? 'judged' : 'pending',
                    'contest_time' => 600 * ($offset + 1),
                    'run_number' => ++$runNumber,
                ]);
            }
        }

        $path = app(BocaWebcastZipBuilder::class)->build($contest);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        return $zip;
    }
}
