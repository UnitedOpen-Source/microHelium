<?php

namespace Tests\Unit\Services;

use App\Models\Answer;
use Tests\TestCase;
use App\Services\AutoJudgeService;
use App\Models\Contest;
use App\Models\Run;
use Helium\User;
use App\Models\Problem;
use App\Models\Language;
use App\Models\TestCase as ModelsTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class AutoJudgeServiceTest extends TestCase
{
    use RefreshDatabase;

    private AutoJudgeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->partialMock(AutoJudgeService::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods();
        });
        $this->service->__construct();
    }

    public function test_judge_updates_run_to_judged_with_correct_verdict()
    {
        Storage::fake('local');
        $this->createTestData();

        $run = Run::first();

        $this->service->shouldReceive('executeJudging')->andReturn([
            'success' => true,
            'verdict' => 'AC',
            'message' => 'Accepted',
            'stdout' => '',
            'stderr' => '',
        ]);
        
        $this->service->judge($run);

        $this->assertDatabaseHas('runs', [
            'id' => $run->id,
            'status' => 'judged',
            'answer_id' => Answer::where('short_name', 'AC')->first()->id,
        ]);
    }

    public function test_execute_judging_with_compile_error()
    {
        Storage::fake('local');
        $this->createTestData();
        $run = Run::first();

        $this->service->shouldReceive('compile')->andReturn([
            'success' => false,
            'verdict' => 'CE',
            'message' => 'Compilation Error',
            'stdout' => '',
            'stderr' => 'error: expected ‘;’ before ‘}’ token',
        ]);
        $this->service->shouldReceive('prepareRunDirectory')->andReturn('/tmp');
        
        $result = $this->service->executeJudging($run);

        $this->assertEquals('CE', $result['verdict']);
    }

    public function test_execute_judging_with_runtime_error()
    {
        Storage::fake('local');
        $this->createTestData();
        $run = Run::first();

        $this->service->shouldReceive('compile')->andReturn(['success' => true]);
        $this->service->shouldReceive('runTestCase')->andReturn([
            'success' => false,
            'verdict' => 'RE',
            'message' => 'Runtime Error',
        ]);
        $this->service->shouldReceive('prepareRunDirectory')->andReturn('/tmp');

        $result = $this->service->executeJudging($run);
        $this->assertEquals('RE', $result['verdict']);
    }

    public function test_execute_judging_with_no_test_cases()
    {
        Storage::fake('local');
        $contest = Contest::factory()->create();
        $user = User::factory()->create();
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id]);
        
        $sourcePath = Storage::putFileAs('sources', new UploadedFile(
            __DIR__ . '/testdata/main.cpp',
            'main.cpp'
        ), 'main.cpp');

        $run = Run::factory()->create([
            'user_id' => $user->user_id,
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'source_file' => $sourcePath,
        ]);

        $this->service->shouldReceive('compile')->andReturn(['success' => true]);
        $this->service->shouldReceive('prepareRunDirectory')->andReturn('/tmp');

        $result = $this->service->executeJudging($run);
        $this->assertEquals('CS', $result['verdict']);
    }

    public function test_execute_judging_with_wrong_answer()
    {
        Storage::fake('local');
        $this->createTestData();
        $run = Run::first();

        $this->service->shouldReceive('compile')->andReturn(['success' => true]);
        $this->service->shouldReceive('runTestCase')->andReturn([
            'success' => false,
            'verdict' => 'WA',
            'message' => 'Wrong Answer',
        ]);
        $this->service->shouldReceive('prepareRunDirectory')->andReturn('/tmp');

        $result = $this->service->executeJudging($run);
        $this->assertEquals('WA', $result['verdict']);
    }

    public function test_get_next_pending_run()
    {
        $run = Run::factory()->create(['status' => 'pending']);
        $problem = Problem::factory()->create(['auto_judge' => true]);
        $run->problem()->associate($problem);
        $run->save();

        $service = new AutoJudgeService();
        $nextRun = $service->getNextPendingRun();
        $this->assertEquals($run->id, $nextRun->id);
    }

    public function test_handle_judging_error()
    {
        Storage::fake('local');
        $this->createTestData();
        $run = Run::first();
        Answer::factory()->create(['short_name' => 'CS', 'contest_id' => $run->contest_id]);

        $this->service->shouldReceive('cleanup');
        $this->service->handleJudgingError($run, new \Exception('Test Error'));

        $this->assertDatabaseHas('runs', [
            'id' => $run->id,
            'status' => 'judged',
            'auto_judge_result' => 'Judging Error',
            'auto_judge_stderr' => 'Test Error',
        ]);
    }

    /**
     * Issue #49 -- command-string generation only. These never need a real
     * bubblewrap: /bin/sh stands in as an existing, executable binary so the
     * generated arguments can be asserted on any platform. Confinement
     * itself is proven by tests/Integration/JudgeSandboxConfinementTest.php,
     * which runs bwrap for real and skips where it isn't installed.
     */
    private function sandboxingService(): AutoJudgeService
    {
        config([
            'autojudge.use_bwrap' => true,
            'autojudge.bwrap_path' => '/bin/sh',
        ]);

        return new AutoJudgeService();
    }

    public function test_wrap_with_bwrap_generates_correct_args()
    {
        $command = $this->sandboxingService()->wrapWithBwrap('echo hello', '/tmp/run_1');

        $this->assertStringContainsString('--unshare-all', $command);
        $this->assertStringContainsString('--die-with-parent', $command);
        $this->assertStringContainsString('--new-session', $command);
        $this->assertStringContainsString('--clearenv', $command);
        $this->assertStringContainsString("--bind '/tmp/run_1' '/tmp/run_1'", $command);
        $this->assertStringContainsString("--chdir '/tmp/run_1'", $command);
        $this->assertStringContainsString("bash -c 'echo hello'", $command);
    }

    public function test_sandbox_never_binds_the_host_root()
    {
        $command = $this->sandboxingService()->wrapWithBwrap('echo hello', '/tmp/run_1');

        // The whole point of #49's "não ler arquivo sentinela fora da
        // tentativa": binding / read-only would confine writes but leave
        // every file on the host readable, .env included.
        $this->assertStringNotContainsString('--ro-bind / /', $command);
        $this->assertStringNotContainsString("--ro-bind '/' '/'", $command);
    }

    public function test_sandbox_does_not_expose_the_application_root()
    {
        $command = $this->sandboxingService()->wrapWithBwrap('echo hello', '/tmp/run_1');

        // base_path() holds .env, the source tree, every other run's
        // directory and every problem's hidden test data.
        $this->assertStringNotContainsString(base_path(), $command);
    }

    public function test_sandbox_binds_the_configured_system_allowlist_read_only()
    {
        config(['autojudge.sandbox_paths' => ['/usr', '/no/such/path']]);
        $command = $this->sandboxingService()->wrapWithBwrap('echo hello', '/tmp/run_1');

        $this->assertStringContainsString("--ro-bind '/usr' '/usr'", $command);
        // Paths absent on this image are skipped, not passed to bwrap as a
        // mount that would fail the whole sandbox.
        $this->assertStringNotContainsString('/no/such/path', $command);
    }

    public function test_sandbox_binds_run_dir_after_the_tmpfs_that_would_shadow_it()
    {
        $command = $this->sandboxingService()->wrapWithBwrap('echo hello', '/tmp/autojudge/run_1');

        // autojudge.work_dir defaults to /tmp/autojudge, so a --tmpfs /tmp
        // applied after the run-dir bind would hide the run directory.
        $this->assertLessThan(
            strpos($command, "--bind '/tmp/autojudge/run_1'"),
            strpos($command, '--tmpfs /tmp'),
            'The --tmpfs /tmp must be applied before the run directory is bound over it.'
        );
    }

    public function test_sandbox_unshares_the_network_by_default()
    {
        $command = $this->sandboxingService()->wrapWithBwrap('echo hello', '/tmp/run_1');

        // --unshare-all already covers the network namespace.
        $this->assertStringContainsString('--unshare-all', $command);
        $this->assertStringNotContainsString('--share-net', $command);
    }

    public function test_sandbox_shares_the_network_only_when_explicitly_asked()
    {
        $command = $this->sandboxingService()
            ->wrapWithBwrap('echo hello', '/tmp/run_1', ['allow_net' => true]);

        // Re-enabling the network takes --share-net; simply omitting
        // --unshare-net does nothing against --unshare-all.
        $this->assertStringContainsString('--share-net', $command);
    }

    public function test_sandbox_exposes_only_the_extra_paths_a_step_asks_for()
    {
        $command = $this->sandboxingService()->wrapWithBwrap('echo hello', '/tmp/run_1', [
            'ro_binds' => ['/usr/bin/env', '/no/such/file'],
        ]);

        $this->assertStringContainsString("--ro-bind '/usr/bin/env' '/usr/bin/env'", $command);
        $this->assertStringNotContainsString('/no/such/file', $command);
    }

    public function test_sandbox_applies_cpu_and_file_size_rlimits()
    {
        $command = $this->sandboxingService()->wrapWithBwrap('echo hello', '/tmp/run_1', [
            'cpu_seconds' => 3,
            'file_size_kb' => 512,
        ]);

        $this->assertStringContainsString("bash -c 'ulimit -t 3; ulimit -f 512; echo hello'", $command);
    }

    public function test_wrap_with_bwrap_is_a_no_op_when_the_sandbox_is_disabled()
    {
        config(['autojudge.use_bwrap' => false]);

        $this->assertSame('echo hello', (new AutoJudgeService())->wrapWithBwrap('echo hello', '/tmp/run_1'));
    }

    public function test_missing_bwrap_binary_throws_runtime_exception()
    {
        config([
            'autojudge.use_bwrap' => true,
            'autojudge.bwrap_path' => '/non/existent/bwrap',
        ]);
        $service = new AutoJudgeService();

        $this->expectException(\App\Exceptions\SandboxUnavailableException::class);
        $this->expectExceptionMessage("Mandatory judge sandbox binary (bwrap) not found");

        $service->wrapWithBwrap('echo hello', '/tmp/run_1');
    }

    public function test_build_compile_command()
    {
        $language = (object) [
            'compile_command' => 'g++ {source} -o {output}'
        ];
        $command = $this->service->buildCompileCommand($language, '/tmp', 'main.cpp');
        $this->assertEquals('g++ main.cpp -o main', $command);
    }

    public function test_compare_output_uses_custom_compare_script_when_present()
    {
        $contest = Contest::factory()->create();
        $problem = Problem::factory()->create(['contest_id' => $contest->id, 'basename' => 'special-judge-problem']);
        $language = Language::factory()->create(['contest_id' => $contest->id, 'extension' => 'cpp']);

        $compareScriptPath = $problem->getCompareScriptPath($language->extension);
        mkdir(dirname($compareScriptPath), 0755, true);
        // A special judge that ignores the expected output entirely and just
        // checks the actual output is non-empty -- proving the plain-diff
        // path (which would fail here, "1\n2" !== "yes") is NOT what decided
        // the verdict.
        file_put_contents($compareScriptPath, "#!/bin/bash\n[ -s \"\$3\" ] && exit 0 || exit 1\n");

        [$inputFile, $expectedFile, $actualFile] = $this->tempFilesFor("1\n2\n", "yes\n");

        try {
            $result = $this->service->compareOutput('1\n2', 'yes', $problem, $language, $inputFile, $expectedFile, $actualFile);
        } finally {
            $this->cleanupTempAndCompareScript($compareScriptPath, $inputFile, $expectedFile, $actualFile);
        }

        $this->assertTrue($result['success']);
        $this->assertEquals('AC', $result['verdict']);
    }

    public function test_compare_output_custom_compare_script_can_reject()
    {
        $contest = Contest::factory()->create();
        $problem = Problem::factory()->create(['contest_id' => $contest->id, 'basename' => 'special-judge-rejects']);
        $language = Language::factory()->create(['contest_id' => $contest->id, 'extension' => 'cpp']);

        $compareScriptPath = $problem->getCompareScriptPath($language->extension);
        mkdir(dirname($compareScriptPath), 0755, true);
        file_put_contents($compareScriptPath, "#!/bin/bash\nexit 1\n");

        [$inputFile, $expectedFile, $actualFile] = $this->tempFilesFor("1\n2\n", "1\n2\n");

        try {
            // Even though the actual output exactly matches the expected
            // output, the custom script is authoritative and rejects it.
            $result = $this->service->compareOutput('1\n2', '1\n2', $problem, $language, $inputFile, $expectedFile, $actualFile);
        } finally {
            $this->cleanupTempAndCompareScript($compareScriptPath, $inputFile, $expectedFile, $actualFile);
        }

        $this->assertFalse($result['success']);
        $this->assertEquals('WA', $result['verdict']);
    }

    public function test_compare_output_falls_back_to_plain_diff_when_no_compare_script_exists()
    {
        $contest = Contest::factory()->create();
        $problem = Problem::factory()->create(['contest_id' => $contest->id, 'basename' => 'no-special-judge']);
        $language = Language::factory()->create(['contest_id' => $contest->id, 'extension' => 'cpp']);

        [$inputFile, $expectedFile, $actualFile] = $this->tempFilesFor("1\n2\n", "3\n");

        try {
            $result = $this->service->compareOutput('3', '3', $problem, $language, $inputFile, $expectedFile, $actualFile);
        } finally {
            $this->cleanupTempAndCompareScript(null, $inputFile, $expectedFile, $actualFile);
        }

        $this->assertTrue($result['success']);
        $this->assertEquals('AC', $result['verdict']);
    }

    /** @return array{0: string, 1: string, 2: string} [inputFile, expectedFile, actualFile] */
    private function tempFilesFor(string $input, string $actualOutput): array
    {
        $inputFile = tempnam(sys_get_temp_dir(), 'in_');
        $expectedFile = tempnam(sys_get_temp_dir(), 'exp_');
        $actualFile = tempnam(sys_get_temp_dir(), 'act_');

        file_put_contents($inputFile, $input);
        file_put_contents($expectedFile, "unused - the custom script decides\n");
        file_put_contents($actualFile, $actualOutput);

        return [$inputFile, $expectedFile, $actualFile];
    }

    private function cleanupTempAndCompareScript(?string $compareScriptPath, string ...$tempFiles): void
    {
        foreach ($tempFiles as $file) {
            @unlink($file);
        }

        if ($compareScriptPath) {
            @unlink($compareScriptPath);
            @rmdir(dirname($compareScriptPath));
        }
    }

    public function test_cleanup()
    {
        Storage::fake('local');
        $this->createTestData();
        $run = Run::first();
        $runDir = storage_path("app/workdir/runs/{$run->id}");
        if (!is_dir($runDir)) {
            mkdir($runDir, 0755, true);
        }
        
        $this->service->cleanup($run);

        $this->assertFalse(Storage::disk('local')->exists("app/workdir/runs/{$run->id}"));
    }
    
    private function createTestData()
    {
        $contest = Contest::factory()->create();
        $user = User::factory()->create();
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create([
            'contest_id' => $contest->id,
            'compile_command' => 'g++',
            'run_command' => './a.out',
        ]);
        Answer::factory()->create(['short_name' => 'AC', 'contest_id' => $contest->id]);
        
        $sourcePath = Storage::putFileAs('sources', new UploadedFile(
            __DIR__ . '/testdata/main.cpp',
            'main.cpp'
        ), 'main.cpp');

        $run = Run::factory()->create([
            'user_id' => $user->user_id,
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'source_file' => $sourcePath,
        ]);

        $inputPath = Storage::putFileAs("problems/{$problem->id}", new UploadedFile(
            __DIR__ . '/testdata/1.in',
            '1.in'
        ), '1.in');
        $outputPath = Storage::putFileAs("problems/{$problem->id}", new UploadedFile(
            __DIR__ . '/testdata/1.out',
            '1.out'
        ), '1.out');
        
        ModelsTestCase::factory()->create([
            'problem_id' => $problem->id,
            'number' => 1,
            'input_file' => $inputPath,
            'output_file' => $outputPath,
        ]);
    }
}
