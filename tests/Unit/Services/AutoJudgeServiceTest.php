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
        mkdir($runDir, 0755, true);
        
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
