<?php

namespace App\Services;

use App\Models\Answer;
use App\Models\ContestLog;
use App\Models\Run;
use App\Models\Score;
use App\Models\TestCase;
use App\Models\Problem;
use Illuminate\Support\Facades\Process;

class AutoJudgeService
{
    public string $workDir;
    protected string $jailPath;
    protected int $defaultTimeLimit;
    protected int $defaultMemoryLimit;
    protected string $safeExecPath;

    public function __construct()
    {
        $this->workDir = config('autojudge.work_dir', '/tmp/autojudge');
        $this->jailPath = config('autojudge.jail_path', '/bocajail');
        $this->defaultTimeLimit = config('autojudge.time_limit', 10);
        $this->defaultMemoryLimit = config('autojudge.memory_limit', 512);
        $this->safeExecPath = config('autojudge.safeexec_path', '/usr/bin/safeexec');
    }

    public function judge(Run $run): void
    {
        $run->update([
            'status' => 'judging',
            'auto_judge_ip' => gethostbyname(gethostname()),
            'auto_judge_start' => now(),
        ]);

        try {
            $result = $this->executeJudging($run);
            $this->updateRunWithResult($run, $result);
        } catch (\Exception $e) {
            $this->handleJudgingError($run, $e);
        }
    }

    protected function executeJudging(Run $run): array
    {
        $problem = $run->problem;
        $language = $run->language;
        $runDir = $this->prepareRunDirectory($run);

        // Step 1: Compile
        $compileResult = $this->compile($run, $runDir);
        if (!$compileResult['success']) {
            return [
                'verdict' => 'CE',
                'message' => 'Compilation Error',
                'stdout' => $compileResult['stdout'] ?? '',
                'stderr' => $compileResult['stderr'] ?? '',
            ];
        }

        // Step 2: Run test cases
        $testCases = $problem->testCases()->orderBy('number')->get();
        if ($testCases->isEmpty()) {
            return [
                'verdict' => 'CS',
                'message' => 'No test cases found',
                'stdout' => '',
                'stderr' => '',
            ];
        }

        foreach ($testCases as $testCase) {
            $testResult = $this->runTestCase($run, $runDir, $testCase);

            if (!$testResult['success']) {
                return $testResult;
            }
        }

        return [
            'verdict' => 'AC',
            'message' => 'Accepted',
            'stdout' => '',
            'stderr' => '',
        ];
    }

    protected function prepareRunDirectory(Run $run): string
    {
        $runDir = "{$this->workDir}/run_{$run->id}";

        if (!is_dir($runDir)) {
            mkdir($runDir, 0755, true);
        }

        // Copy source file
        $sourceContent = file_get_contents($run->getSourcePath());
        $sourceFile = "{$runDir}/{$run->filename}";
        file_put_contents($sourceFile, $sourceContent);

        return $runDir;
    }

    protected function compile(Run $run, string $runDir): array
    {
        $language = $run->language;
        $problem = $run->problem;

        // Check for custom compile script
        $compileScript = $problem->getCompileScriptPath($language->extension);
        if (file_exists($compileScript)) {
            return $this->runCustomScript($compileScript, $runDir, $run);
        }

        // Use default compile command
        $compileCommand = $this->buildCompileCommand($language, $runDir, $run->filename);

        $result = Process::timeout($this->defaultTimeLimit * 2)
            ->path($runDir)
            ->run($compileCommand);

        return [
            'success' => $result->successful(),
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
            'exit_code' => $result->exitCode(),
        ];
    }

    protected function buildCompileCommand(object $language, string $runDir, string $filename): string
    {
        $command = $language->compile_command;
        $basename = pathinfo($filename, PATHINFO_FILENAME);

        $command = str_replace('{source}', $filename, $command);
        $command = str_replace('{output}', $basename, $command);
        $command = str_replace('{basename}', $basename, $command);

        return $command;
    }

    protected function runTestCase(Run $run, string $runDir, TestCase $testCase): array
    {
        $problem = $run->problem;
        $language = $run->language;

        $inputFile = $testCase->getInputPath();
        $expectedOutput = file_get_contents($testCase->getOutputPath());
        $outputFile = "{$runDir}/output_{$testCase->number}.txt";

        // Run the program
        $runResult = $this->executeProgram($run, $runDir, $inputFile, $outputFile);

        if (!$runResult['success']) {
            return $runResult;
        }

        // Compare output
        $actualOutput = file_exists($outputFile) ? file_get_contents($outputFile) : '';
        $compareResult = $this->compareOutput($expectedOutput, $actualOutput, $problem, $language);

        return $compareResult;
    }

    protected function executeProgram(Run $run, string $runDir, string $inputFile, string $outputFile): array
    {
        $language = $run->language;
        $problem = $run->problem;
        $basename = pathinfo($run->filename, PATHINFO_FILENAME);

        $timeLimit = $problem->time_limit;
        $memoryLimit = $problem->memory_limit;

        // Check for custom run script
        $runScript = $problem->getRunScriptPath($language->extension);
        if (file_exists($runScript)) {
            $command = "bash {$runScript} {$basename} {$inputFile} {$timeLimit} 1 {$memoryLimit} 1024";
        } else {
            $runCommand = $language->run_command;
            $runCommand = str_replace('{executable}', $basename, $runCommand);
            $runCommand = str_replace('{classname}', $basename, $runCommand);
            $runCommand = str_replace('{source}', $run->filename, $runCommand);
            $runCommand = str_replace('{memory}', $memoryLimit, $runCommand);

            $command = $runCommand . " < {$inputFile} > {$outputFile} 2>&1";
        }

        // Use safeexec if available
        if (file_exists($this->safeExecPath)) {
            $command = $this->wrapWithSafeExec($command, $timeLimit, $memoryLimit, $runDir);
        }

        $result = Process::timeout($timeLimit + 5)
            ->path($runDir)
            ->run($command);

        $exitCode = $result->exitCode();

        // Interpret exit codes
        if ($exitCode === 137 || $exitCode === 9) {
            return [
                'success' => false,
                'verdict' => 'TLE',
                'message' => 'Time Limit Exceeded',
                'stdout' => $result->output(),
                'stderr' => $result->errorOutput(),
            ];
        }

        if ($exitCode === 139 || $exitCode === 11) {
            return [
                'success' => false,
                'verdict' => 'RE',
                'message' => 'Runtime Error (Segmentation Fault)',
                'stdout' => $result->output(),
                'stderr' => $result->errorOutput(),
            ];
        }

        if ($exitCode !== 0) {
            return [
                'success' => false,
                'verdict' => 'RE',
                'message' => "Runtime Error (Exit code: {$exitCode})",
                'stdout' => $result->output(),
                'stderr' => $result->errorOutput(),
            ];
        }

        return [
            'success' => true,
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
        ];
    }

    protected function wrapWithSafeExec(string $command, int $timeLimit, int $memoryLimit, string $runDir): string
    {
        $memoryKb = $memoryLimit * 1024;

        return "{$this->safeExecPath} " .
            "-t {$timeLimit} " .
            "-T " . ($timeLimit + 5) . " " .
            "-m {$memoryKb} " .
            "-d {$memoryKb} " .
            "-f 10 " .
            "-R {$runDir} " .
            "-- {$command}";
    }

    protected function compareOutput(string $expected, string $actual, Problem $problem, object $language): array
    {
        // Check for custom compare script
        $compareScript = $problem->getCompareScriptPath($language->extension);
        if (file_exists($compareScript)) {
            // Custom comparison would go here
        }

        // Normalize line endings
        $expected = str_replace("\r\n", "\n", trim($expected));
        $actual = str_replace("\r\n", "\n", trim($actual));

        // Exact match
        if ($expected === $actual) {
            return [
                'success' => true,
                'verdict' => 'AC',
                'message' => 'Accepted',
                'stdout' => '',
                'stderr' => '',
            ];
        }

        // Match ignoring trailing whitespace
        $expectedLines = array_map('rtrim', explode("\n", $expected));
        $actualLines = array_map('rtrim', explode("\n", $actual));

        if ($expectedLines === $actualLines) {
            return [
                'success' => true,
                'verdict' => 'AC',
                'message' => 'Accepted (whitespace tolerance)',
                'stdout' => '',
                'stderr' => '',
            ];
        }

        // Presentation error check
        $expectedNormalized = preg_replace('/\s+/', ' ', $expected);
        $actualNormalized = preg_replace('/\s+/', ' ', $actual);

        if ($expectedNormalized === $actualNormalized) {
            return [
                'success' => false,
                'verdict' => 'PE',
                'message' => 'Presentation Error',
                'stdout' => '',
                'stderr' => 'Output differs only in whitespace formatting',
            ];
        }

        return [
            'success' => false,
            'verdict' => 'WA',
            'message' => 'Wrong Answer',
            'stdout' => '',
            'stderr' => '',
        ];
    }

    protected function runCustomScript(string $script, string $runDir, Run $run): array
    {
        $result = Process::timeout($this->defaultTimeLimit * 2)
            ->path($runDir)
            ->run("bash {$script} {$run->filename}");

        return [
            'success' => $result->exitCode() === 0,
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
            'exit_code' => $result->exitCode(),
        ];
    }

    protected function updateRunWithResult(Run $run, array $result): void
    {
        $answer = Answer::where('contest_id', $run->contest_id)
            ->where('short_name', $result['verdict'])
            ->first();

        $run->update([
            'status' => 'judged',
            'answer_id' => $answer?->id,
            'auto_judge_end' => now(),
            'auto_judge_result' => $result['message'],
            'auto_judge_stdout' => substr($result['stdout'] ?? '', 0, 65535),
            'auto_judge_stderr' => substr($result['stderr'] ?? '', 0, 65535),
            'judged_time' => $run->contest->getContestTime(),
        ]);

        // Update score
        Score::updateScore($run);

        // Cleanup
        $this->cleanup($run);

        ContestLog::info($run->contest_id, "Run #{$run->run_number} judged: {$result['verdict']}", [
            'user_id' => $run->user_id,
            'problem_id' => $run->problem_id,
            'verdict' => $result['verdict'],
        ]);
    }

    protected function handleJudgingError(Run $run, \Exception $e): void
    {
        $answer = Answer::where('contest_id', $run->contest_id)
            ->where('short_name', 'CS')
            ->first();

        $run->update([
            'status' => 'judged',
            'answer_id' => $answer?->id,
            'auto_judge_end' => now(),
            'auto_judge_result' => 'Judging Error',
            'auto_judge_stderr' => $e->getMessage(),
        ]);

        ContestLog::error($run->contest_id, "Judging error for run #{$run->run_number}: {$e->getMessage()}", [
            'user_id' => $run->user_id,
            'exception' => $e->getTraceAsString(),
        ]);

        $this->cleanup($run);
    }

    protected function cleanup(Run $run): void
    {
        $runDir = "{$this->workDir}/run_{$run->id}";
        if (is_dir($runDir)) {
            $this->recursiveDelete($runDir);
        }
    }

    protected function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "{$dir}/{$file}";
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function getNextPendingRun(): ?Run
    {
        return Run::where('status', 'pending')
            ->whereHas('problem', fn($q) => $q->where('auto_judge', true))
            ->orderBy('created_at')
            ->first();
    }
}