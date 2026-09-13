<?php

namespace Tests\Unit\Services;

use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\TestCase as ProblemTestCase;
use App\Services\AutoJudgeService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #116 -- judging a run must not touch the database.
 *
 * This is the property the whole distributed design of #53 rests on. A
 * judgehost is a machine in a partner institution's rack: it polls over
 * HTTP, holds a token scoped to the one run it is judging, and has no
 * reason -- and must have no means -- to reach the contest database. If
 * judging needed a database connection, every partner would need
 * credentials to the whole contest, and "no inbound port" would be the
 * only thing left of the isolation.
 *
 * So this is a test about an *absence*, which is why it counts queries
 * rather than asserting on a verdict. It fails the moment anyone puts a
 * query back into the judging path, which is exactly when someone needs to
 * know.
 */
class JudgeWithoutDatabaseTest extends TestCase
{
    private string $storageRelative = 'judge_without_db_test';

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = storage_path('app/'.$this->storageRelative);
        @mkdir($this->workDir, 0755, true);

        config([
            'autojudge.use_bwrap' => false,
            'autojudge.work_dir' => sys_get_temp_dir().'/judge_without_db_'.getmypid(),
            // GNU time's -f is not portable, and the peak-RSS wrapper is not
            // what this test is about.
            'autojudge.rss_time_path' => '/nonexistent/gnu-time',
            'autojudge.cgroup_root' => '/sys/fs/cgroup/nao-delegado',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workDir);

        $work = sys_get_temp_dir().'/judge_without_db_'.getmypid();
        foreach (glob($work.'/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($work.'/*') ?: [] as $dir) {
            is_dir($dir) ? @rmdir($dir) : @unlink($dir);
        }
        @rmdir($work);

        parent::tearDown();
    }

    /**
     * Everything an agent would have after fetch-work plus the payload
     * endpoints from #113: models built in memory from a JSON response, and
     * bytes written to local files. Nothing is persisted -- none of these
     * rows exist in any database.
     */
    private function hydratedRun(string $solution, string $input, string $expected): Run
    {
        file_put_contents($this->workDir.'/sol.sh', $solution);
        file_put_contents($this->workDir.'/1.in', $input);
        file_put_contents($this->workDir.'/1.out', $expected);

        $language = new Language([
            'extension' => 'sh',
            'compile_command' => 'true',
            'run_command' => 'bash {source}',
        ]);
        $language->id = 1;

        $problem = new Problem([
            'contest_id' => 1,
            'time_limit' => 5,
            'memory_limit' => 256,
            'auto_judge' => true,
            'basename' => 'sem-pacote',
        ]);
        $problem->id = 1;
        // Both relations pre-set, the way an agent that was handed them in a
        // payload would have them.
        $problem->setRelation('languageLimits', new EloquentCollection);

        $testCase = new ProblemTestCase([
            'number' => 1,
            'input_file' => $this->storageRelative.'/1.in',
            'output_file' => $this->storageRelative.'/1.out',
        ]);
        $testCase->id = 1;
        $problem->setRelation('testCases', new EloquentCollection([$testCase]));

        $run = new Run([
            'contest_id' => 1,
            'filename' => 'sol.sh',
            'source_file' => $this->storageRelative.'/sol.sh',
        ]);
        $run->id = 999;
        $run->setRelation('problem', $problem);
        $run->setRelation('language', $language);

        return $run;
    }

    /**
     * @return array{0: array, 1: array} the verdict, and every query run
     */
    private function judgeAndLogQueries(Run $run): array
    {
        $service = new AutoJudgeService;

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $verdict = (new \ReflectionMethod($service, 'executeJudging'))->invoke($service, $run);
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
        }

        return [$verdict, $queries];
    }

    public function test_a_correct_submission_is_judged_without_a_single_query(): void
    {
        [$verdict, $queries] = $this->judgeAndLogQueries(
            $this->hydratedRun("read a b\necho \$((a + b))\n", "3 4\n", "7\n")
        );

        $this->assertSame('AC', $verdict['verdict']);

        $this->assertSame(
            [],
            array_column($queries, 'query'),
            'Judging reached the database. A judgehost has no credentials for it -- see issue #116.'
        );
    }

    public function test_a_wrong_submission_is_judged_without_a_single_query(): void
    {
        // The verdict path matters as much as the happy one: an agent that
        // could only report AC without a database would be useless.
        [$verdict, $queries] = $this->judgeAndLogQueries(
            $this->hydratedRun("read a b\necho 0\n", "3 4\n", "7\n")
        );

        $this->assertSame('WA', $verdict['verdict']);
        $this->assertSame([], array_column($queries, 'query'));
    }

    public function test_a_failed_compilation_is_judged_without_a_single_query(): void
    {
        $run = $this->hydratedRun("echo hi\n", "3 4\n", "7\n");
        $run->language->compile_command = 'false';

        [$verdict, $queries] = $this->judgeAndLogQueries($run);

        $this->assertSame('CE', $verdict['verdict']);
        $this->assertSame([], array_column($queries, 'query'));
    }

    /**
     * The relation being loaded is what makes the difference, so this pins
     * the fallback rather than leaving it to chance: a Problem whose test
     * cases were never loaded still queries for them, which is what the
     * single-machine judge has always done and must keep doing.
     */
    public function test_a_problem_with_no_loaded_test_cases_still_queries_for_them(): void
    {
        $run = $this->hydratedRun("read a b\necho \$((a + b))\n", "3 4\n", "7\n");
        $run->problem->unsetRelation('testCases');

        [, $queries] = $this->judgeAndLogQueries($run);

        $this->assertNotEmpty(
            array_filter(array_column($queries, 'query'), fn ($sql) => str_contains($sql, 'test_cases')),
            'Without a loaded relation the judge must still fetch test cases from the database.'
        );
    }
}
