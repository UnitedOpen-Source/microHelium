<?php

namespace Tests\Unit\Services;

use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\TestCase as ProblemTestCase;
use App\Services\AutoJudgeService;
use App\Services\JudgeWorkQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\TestCase;

/**
 * Issue #120 -- a run must get the same verdict wherever it is judged.
 *
 * Three hooks in AutoJudgeService come out of the problem package on disk:
 * compile, run and compare. Every one is applied behind a file_exists()
 * that falls through to the default when the file is absent. A judgehost
 * (#53) has no package, so the fallback is not a degradation there -- it is
 * a different set of rules.
 *
 * The compare hook is the dangerous one, and the first test here is the
 * whole reason the payload now carries a package manifest: it fails in
 * silence and in favour of the wrong answer, marking a correct submission
 * WA with no error raised anywhere.
 */
class RemoteJudgingParityTest extends TestCase
{
    private string $storageRelative = 'remote_parity_test';

    private string $workDir;

    /** @var list<string> */
    private array $packageDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = storage_path('app/'.$this->storageRelative);
        @mkdir($this->workDir, 0755, true);

        config([
            'autojudge.use_bwrap' => false,
            'autojudge.work_dir' => sys_get_temp_dir().'/remote_parity_'.getmypid(),
            'autojudge.rss_time_path' => '/nonexistent/gnu-time',
            'autojudge.cgroup_root' => '/sys/fs/cgroup/nao-delegado',
        ]);

        file_put_contents($this->workDir.'/sol.sh', "echo 0.3333333\n");
        file_put_contents($this->workDir.'/1.in', "1 3\n");
        // Differs from the submission's output in the seventh decimal: right
        // under a tolerance checker, wrong under exact comparison.
        file_put_contents($this->workDir.'/1.out', "0.333333\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workDir);

        foreach ($this->packageDirs as $dir) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
            @rmdir(dirname($dir));
        }

        $work = sys_get_temp_dir().'/remote_parity_'.getmypid();
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
     * A checker that accepts anything within 1e-6, written where
     * Problem::getCompareScriptPath() actually looks for it.
     */
    private function writeToleranceChecker(Problem $problem, string $extension): void
    {
        $dir = storage_path("app/problems/{$problem->contest_id}/{$problem->basename}/compare");
        @mkdir($dir, 0755, true);
        $this->packageDirs[] = $dir;

        file_put_contents($dir.'/'.$extension, <<<'SH'
#!/bin/sh
e=$(cat "$2"); a=$(cat "$3")
awk -v e="$e" -v a="$a" 'BEGIN{d=e-a; if(d<0)d=-d; exit (d<0.000001)?0:1}'
SH);
        chmod($dir.'/'.$extension, 0755);
    }

    private function runFor(string $basename): Run
    {
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
            'basename' => $basename,
        ]);
        $problem->id = 1;
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
        $run->id = 1;
        $run->setRelation('problem', $problem);
        $run->setRelation('language', $language);

        return $run;
    }

    private function judge(Run $run): array
    {
        $service = new AutoJudgeService;

        return (new \ReflectionMethod($service, 'executeJudging'))->invoke($service, $run);
    }

    /**
     * The defect, pinned. Not a hypothetical: this is the measurement that
     * opened #120.
     */
    public function test_a_missing_compare_script_silently_changes_the_verdict(): void
    {
        $withPackage = $this->runFor('parity-pkg');
        $this->writeToleranceChecker($withPackage->problem, 'sh');

        // The same submission, on a machine that does not have the package.
        $withoutPackage = $this->runFor('package-the-agent-does-not-have');

        $this->assertSame('AC', $this->judge($withPackage)['verdict']);
        $this->assertSame(
            'WA',
            $this->judge($withoutPackage)['verdict'],
            'If this stops being WA the divergence is gone and the manifest may no longer be needed -- '
            .'check why before deleting anything.'
        );
    }

    /**
     * And so the payload has to say the script is there, because that is the
     * only thing standing between an agent and a wrong verdict: an agent
     * that sees a non-null hook and cannot fetch it must give the run back
     * rather than judge it by the default rules.
     */
    public function test_the_work_payload_tells_an_agent_the_checker_exists(): void
    {
        $run = $this->runFor('parity-pkg');
        $this->writeToleranceChecker($run->problem, 'sh');

        $manifest = app(JudgeWorkQueue::class)->packageManifest($run->problem, $run->language);

        $this->assertNotNull($manifest['compare']);
        $this->assertSame(
            hash_file('sha256', $run->problem->getCompareScriptPath('sh')),
            $manifest['compare']['sha256']
        );
    }

    public function test_a_problem_without_a_checker_is_the_same_everywhere(): void
    {
        // The other half: where no hook is overridden, a remote judge and a
        // local one are already in agreement and nothing needs fetching.
        file_put_contents($this->workDir.'/1.out', "0.3333333\n");

        $manifest = app(JudgeWorkQueue::class)->packageManifest(
            $this->runFor('no-package-at-all')->problem,
            $this->runFor('no-package-at-all')->language
        );

        $this->assertSame(['compile' => null, 'run' => null, 'compare' => null], $manifest);
        $this->assertSame('AC', $this->judge($this->runFor('no-package-at-all'))['verdict']);
    }
}
