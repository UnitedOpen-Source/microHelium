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
