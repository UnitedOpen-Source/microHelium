<?php

namespace App\Services;

use App\Exceptions\SandboxUnavailableException;
use App\Models\Answer;
use App\Models\ContestLog;
use App\Models\Run;
use App\Models\Score;
use App\Models\TestCase;
use App\Models\Problem;
use Illuminate\Support\Facades\Process;

class AutoJudgeService
{
    /**
     * Marks the peak-RSS line `time -f` writes, so it can be told apart
     * from anything else that reaches that stream.
     */
    private const RSS_PREFIX = 'MHRSS';

    public string $workDir;
    protected int $defaultTimeLimit;
    protected int $defaultMemoryLimit;
    protected string $bwrapPath;
    protected bool $useBwrap;
    protected array $sandboxPaths;
    protected int $compileMaxFileKb;

    protected int $compileMemoryMb;
    protected int $runMaxFileKb;
    protected int $runMaxProcesses;

    protected array $memoryGraceMb;

    protected string $rssTimePath;
    protected ?bool $sandboxProbeFailed = null;

    protected CgroupMemoryLimiter $cgroup;

    public function __construct(?CgroupMemoryLimiter $cgroup = null)
    {
        $this->cgroup = $cgroup ?? new CgroupMemoryLimiter;

        $this->workDir = config('autojudge.work_dir', '/tmp/autojudge');
        $this->defaultTimeLimit = config('autojudge.time_limit', 10);
        $this->defaultMemoryLimit = config('autojudge.memory_limit', 512);
        $this->bwrapPath = config('autojudge.bwrap_path', '/usr/bin/bwrap');
        $this->useBwrap = config('autojudge.use_bwrap', true);
        $this->sandboxPaths = config('autojudge.sandbox_paths', ['/usr', '/bin', '/sbin', '/lib', '/lib64', '/etc', '/opt', '/go']);
        $this->compileMaxFileKb = (int) config('autojudge.compile_max_file_kb', 262144);
        $this->compileMemoryMb = (int) config('autojudge.compile_memory_mb', 2048);
        $this->runMaxFileKb = (int) config('autojudge.run_max_file_kb', 32768);
        $this->runMaxProcesses = (int) config('autojudge.run_max_processes', 256);
        $this->memoryGraceMb = config('autojudge.memory_grace_mb', ['default' => 64]);
        $this->rssTimePath = (string) config('autojudge.rss_time_path', '/usr/bin/time');
    }

    /**
     * Issue #49 -- wrap $command so it runs confined, per
     * docs/specs/49-judge-isolation.md.
     *
     * The sandbox is an allowlist, not a read-only view of the host: only
     * `autojudge.sandbox_paths` (the language toolchains) plus whatever this
     * specific step declares in $options['ro_binds'] is visible. The
     * application root is never mounted, so .env, the source tree, other
     * runs' directories and other problems' hidden test data simply do not
     * exist inside the sandbox.
     *
     * Supported $options:
     *   allow_net (bool)     -- re-share the network namespace. Off by default.
     *   ro_binds (string[])  -- extra host paths to expose read-only, each at
     *                           its own path so the caller's command string
     *                           needs no rewriting. Missing paths are skipped.
     *   cpu_seconds (int)    -- `ulimit -t` inside the sandbox.
     *   file_size_kb (int)   -- `ulimit -f` inside the sandbox.
     *   max_processes (int)  -- `ulimit -u` inside the sandbox.
     *   memory_kb (int)      -- `ulimit -v` inside the sandbox. Address
     *                           space, not resident memory, so only for the
     *                           languages measured to tolerate it.
     */
    public function wrapWithBwrap(string $command, string $runDir, array $options = []): string
    {
        if (!$this->useBwrap) {
            return $command;
        }

        if (!file_exists($this->bwrapPath) || !is_executable($this->bwrapPath)) {
            throw new SandboxUnavailableException(
                "Mandatory judge sandbox binary (bwrap) not found or not executable at '{$this->bwrapPath}'. Refusing unconfined host execution."
            );
        }

        $args = [escapeshellarg($this->bwrapPath), '--unshare-all'];

        // --unshare-all ALREADY unshares the network namespace. Re-enabling
        // it takes an explicit --share-net; merely omitting --unshare-net
        // does nothing.
        if ($options['allow_net'] ?? false) {
            $args[] = '--share-net';
        }

        // setsid(), so sandboxed code cannot push characters back into the
        // judge's controlling terminal with TIOCSTI.
        $args[] = '--new-session';
        $args[] = '--die-with-parent';

        foreach ($this->sandboxPaths as $path) {
            $args = array_merge($args, $this->roBindArgs($path));
        }

        // Private scratch space. Must come before the $runDir bind below:
        // autojudge.work_dir defaults to /tmp/autojudge, and a --tmpfs would
        // otherwise shadow the run directory mounted under it.
        $args[] = '--tmpfs /tmp';
        $args[] = '--tmpfs /var/tmp';
        $args[] = '--proc /proc';
        $args[] = '--dev /dev';

        foreach ($options['ro_binds'] ?? [] as $path) {
            $args = array_merge($args, $this->roBindArgs($path));
        }

        // The only writable path, and the last mount applied.
        $args[] = '--bind ' . escapeshellarg($runDir) . ' ' . escapeshellarg($runDir);
        $args[] = '--chdir ' . escapeshellarg($runDir);

        $envPath = getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        $args[] = '--clearenv';
        $args[] = '--setenv PATH ' . escapeshellarg($envPath);
        $args[] = '--setenv LANG ' . escapeshellarg('C.UTF-8');
        $args[] = '--setenv HOME ' . escapeshellarg($runDir);
        $args[] = '--setenv TMPDIR ' . escapeshellarg($runDir);

        // .NET's default W^X JIT double-maps executable memory through a
        // memfd it ftruncates to a large size, and RLIMIT_FSIZE applies to
        // that -- so `dotnet` dies with SIGXFSZ under any `ulimit -f` at all,
        // 1 GB included, both when building and when running. Turning the
        // double mapping off is the documented escape hatch and costs
        // nothing here; every other language ignores the variable.
        $args[] = '--setenv DOTNET_EnableWriteXorExecute 0';

        $args[] = 'bash -c ' . escapeshellarg($this->rlimitPrologue($options) . $command);

        return implode(' ', $args);
    }

    /**
     * `--ro-bind <path> <path>`, or nothing at all when the path is absent
     * on this image -- one sandbox_paths list has to serve Dockerfile,
     * Dockerfile.dev and Dockerfile.judge, which don't install the same set.
     *
     * @return string[]
     */
    protected function roBindArgs(mixed $path): array
    {
        if (!is_string($path) || $path === '' || !file_exists($path)) {
            return [];
        }

        return ['--ro-bind ' . escapeshellarg($path) . ' ' . escapeshellarg($path)];
    }

    /**
     * The `ulimit -v` value for this language: the problem's memory limit
     * plus a per-language grace.
     *
     * This is a crash barrier, not the measurement. `ulimit -v` caps
     * ADDRESS SPACE, and the JVM, Go and V8 reserve large virtual ranges at
     * startup -- without headroom they refuse to boot however little they
     * actually touch. The verdict comes from peak RSS instead
     * (peakRssKb()), which is how DMOJ produces an MLE with no cgroups and
     * no privileges; the grace numbers are DMOJ's own shipped table.
     *
     * Issue #86 -- and it is a barrier of last resort. Where a cgroup
     * memory.max is available it caps RESIDENT memory, which is both a
     * harder bound and one every language tolerates, and the address-space
     * approximation is dropped rather than stacked under it: the barrier
     * sits at limit+grace where memory.max sits at the limit, so it can
     * never bind first, while it can still fail a runtime that reserves
     * address space it never touches. See config/autojudge.php for the
     * measurement.
     */
    protected function addressSpaceLimitKbFor(object $language, int $memoryLimitMb): ?int
    {
        if ($this->cgroup->isAvailable()) {
            return null;
        }

        $extension = $language->extension ?? '';

        // An explicit null means this runtime gets no barrier: see the
        // measurements in config/autojudge.php for why a JVM or .NET one
        // would have to be seven to fifteen times the limit to let the
        // runtime start, at which point it bounds nothing.
        if (array_key_exists($extension, $this->memoryGraceMb) && $this->memoryGraceMb[$extension] === null) {
            return null;
        }

        $grace = (int) ($this->memoryGraceMb[$extension] ?? $this->memoryGraceMb['default'] ?? 64);

        return (max(1, $memoryLimitMb) + $grace) * 1024;
    }

    /**
     * Wraps a run so its peak resident set size is recorded.
     *
     * `time -f '%M'` writes the peak RSS in KB to ITS OWN stderr, which is
     * why the measured command is re-nested in its own `bash -c`: the
     * submission's stderr is already merged into the output file by the
     * caller's redirections, and mixing the measurement into that file
     * would corrupt the diff.
     *
     * If the binary is absent the command is returned untouched -- a
     * missing measurement must not stop a contest.
     */
    protected function withPeakRssMeasurement(string $command, string $rssPath): string
    {
        if (! @is_executable($this->rssTimePath)) {
            return $command;
        }

        return sprintf(
            '%s -f %s bash -c %s 2> %s',
            escapeshellarg($this->rssTimePath),
            escapeshellarg(self::RSS_PREFIX.' %M'),
            escapeshellarg($command),
            escapeshellarg($rssPath)
        );
    }

    /**
     * Peak RSS in KB for the run just finished, or null when it was not
     * measured.
     */
    protected function peakRssKb(string $rssPath): ?int
    {
        if (! is_file($rssPath)) {
            return null;
        }

        $contents = (string) @file_get_contents($rssPath);
        @unlink($rssPath);

        if (preg_match('/'.preg_quote(self::RSS_PREFIX, '/').'\s+(\d+)/', $contents, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Resource limits applied inside the sandbox, as a `bash -c` prologue.
     */
    protected function rlimitPrologue(array $options): string
    {
        $prologue = '';

        if (!empty($options['cpu_seconds'])) {
            $prologue .= 'ulimit -t ' . (int) $options['cpu_seconds'] . '; ';
        }

        // bash counts -f in 1024-byte increments.
        if (!empty($options['file_size_kb'])) {
            $prologue .= 'ulimit -f ' . (int) $options['file_size_kb'] . '; ';
        }

        // Caps fork bombs. Not applied to compilation, where a build tool
        // legitimately fans out across cores.
        if (!empty($options['max_processes'])) {
            $prologue .= 'ulimit -u ' . (int) $options['max_processes'] . '; ';
        }

        // Issue #86 -- only for the languages memoryRlimitKbFor() allows.
        if (!empty($options['memory_kb'])) {
            $prologue .= 'ulimit -v ' . (int) $options['memory_kb'] . '; ';
        }

        return $prologue;
    }

    /**
     * The {judge_runtime} helper scripts (resources/judge-runtime/...), which
     * some languages' compile/run commands shell out to. It lives under the
     * application root, which the sandbox does not mount, so it has to be
     * bound explicitly.
     */
    protected function judgeRuntimePath(): string
    {
        return base_path('resources/judge-runtime');
    }

    /**
     * Turn a bwrap start-up failure into a CS verdict rather than a CE/RE
     * blamed on the submitter (docs/specs/49-judge-isolation.md: "histórico
     * distingue indisponibilidade técnica de resposta incorreta").
     *
     * bwrap prints its own failures on stderr prefixed with "bwrap: " and
     * never executes the payload -- but submitted code controls the stderr
     * we are reading here and could forge that prefix to fake an
     * infrastructure failure. So a match is only a hypothesis: it is
     * confirmed by actually trying to start an empty sandbox.
     */
    protected function assertSandboxStarted(\Illuminate\Contracts\Process\ProcessResult $result): void
    {
        if (!$this->useBwrap || $result->exitCode() === 0) {
            return;
        }

        if (!preg_match('/^bwrap: .*/m', $result->errorOutput(), $matches)) {
            return;
        }

        if (!$this->sandboxProbeFails()) {
            return;
        }

        throw new SandboxUnavailableException(
            'Judge sandbox failed to start: ' . trim($matches[0])
        );
    }

    /**
     * Can bwrap start an empty sandbox at all? Memoised per service instance;
     * only ever reached on an error path.
     */
    protected function sandboxProbeFails(): bool
    {
        if ($this->sandboxProbeFailed !== null) {
            return $this->sandboxProbeFailed;
        }

        $probeDir = sys_get_temp_dir();
        $probe = Process::timeout(10)->run(
            $this->wrapWithBwrap('exit 0', $probeDir)
        );

        return $this->sandboxProbeFailed = $probe->exitCode() !== 0;
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

    /**
     * Issue #116 -- judge a run and return the verdict, writing nothing.
     *
     * The entry point a judgehost agent uses. judge() above owns the run's
     * lifecycle: it marks the row `judging`, records the verdict, moves the
     * scoreboard. An agent owns none of that -- the run is already
     * `judging` because the server set it when the lease was granted, and
     * the verdict goes back over HTTP for the server to record (#113), so
     * the scoreboard, the first-solve balloon and the contest log all
     * happen once, in one place, on the machine that has the database.
     *
     * #119 measured that this path issues no queries at all when the
     * relations are loaded, which is what makes it safe to run on a machine
     * with no database credentials.
     *
     * @return array{verdict: string, message?: string, stdout?: string, stderr?: string}
     */
    public function judgeWithoutPersisting(Run $run): array
    {
        return $this->executeJudging($run);
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
        // Issue #116: use the relation when it is already loaded, the same
        // idiom Problem::limitOverrideFor() uses. A judgehost agent holds
        // its test cases from an HTTP payload and has no database to query;
        // with the relation set, executeJudging() touches none.
        $testCases = $problem->relationLoaded('testCases')
            ? $problem->testCases->sortBy('number')->values()
            : $problem->testCases()->orderBy('number')->get();
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
        $command = $this->wrapWithBwrap($compileCommand, $runDir, [
            'ro_binds' => [$this->judgeRuntimePath()],
            'cpu_seconds' => $this->defaultTimeLimit * 2,
            'file_size_kb' => $this->compileMaxFileKb,
        ]);

        return $this->runCompileStep($command, $runDir, $this->compileCgroupName($run));
    }

    protected function buildCompileCommand(object $language, string $runDir, string $filename): string
    {
        $command = $language->compile_command;
        $basename = pathinfo($filename, PATHINFO_FILENAME);

        $command = str_replace('{judge_runtime}', base_path('resources/judge-runtime'), $command);
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
        $compareResult = $this->compareOutput(
            $expectedOutput,
            $actualOutput,
            $problem,
            $language,
            $inputFile,
            $testCase->getOutputPath(),
            $outputFile
        );

        return $compareResult;
    }

    protected function executeProgram(Run $run, string $runDir, string $inputFile, string $outputFile): array
    {
        $language = $run->language;
        $problem = $run->problem;
        $basename = pathinfo($run->filename, PATHINFO_FILENAME);

        $timeLimit = $problem->getTimeLimitFor($language);
        $memoryLimit = $problem->getMemoryLimitFor($language);

        // Check for custom run script
        $runScript = $problem->getRunScriptPath($language->extension);
        if (file_exists($runScript)) {
            $command = "bash {$runScript} {$basename} {$inputFile} {$timeLimit} 1 {$memoryLimit} 1024";
        } else {
            $runCommand = $language->run_command;
            $runCommand = str_replace('{judge_runtime}', base_path('resources/judge-runtime'), $runCommand);
            $runCommand = str_replace('{executable}', $basename, $runCommand);
            $runCommand = str_replace('{classname}', $basename, $runCommand);
            $runCommand = str_replace('{source}', $run->filename, $runCommand);
            $runCommand = str_replace('{memory}', $memoryLimit, $runCommand);

            $command = $runCommand . " < {$inputFile} > {$outputFile} 2>&1";
        }

        // The test case input lives under storage/app/problems, i.e. inside
        // the application root the sandbox does not mount. Bind this one
        // file -- not the problems tree, which would hand submitted code
        // every problem's hidden test data.
        // Issue #86: measured inside the sandbox, so the peak belongs to the
        // submission and not to bwrap or the judge.
        $rssPath = $runDir.'/.mhrss_'.$run->id;
        $command = $this->withPeakRssMeasurement($command, $rssPath);

        $command = $this->wrapWithBwrap($command, $runDir, [
            'allow_net' => false,
            'ro_binds' => [$inputFile, $this->judgeRuntimePath(), file_exists($runScript) ? $runScript : null],
            'cpu_seconds' => $timeLimit,
            'file_size_kb' => $this->runMaxFileKb,
            'max_processes' => $this->runMaxProcesses,
            'memory_kb' => $this->addressSpaceLimitKbFor($language, $memoryLimit),
        ]);

        // Issue #86 -- outermost, so the cgroup is joined before bwrap
        // starts and covers everything inside it. A no-op where no
        // delegated subtree exists.
        $cgroupName = 'run_'.$run->id.'_'.getmypid();
        $command = $this->cgroup->confine($command, $cgroupName, $memoryLimit);

        // Released in a finally, because the two ways out of this block
        // that are not a return both throw: Process::run() raises
        // ProcessTimedOutException when the backstop fires, and
        // assertSandboxStarted() raises when bwrap never started. Releasing
        // only on the happy path would leave the cgroup behind on exactly
        // the runs most likely to still have a process inside it -- and a
        // cgroup with a live process in it is holding the memory this is
        // supposed to bound. create() reuses a stale directory only when
        // the same name recurs, and the name carries the pid, so after a
        // worker is recycled it never does.
        try {
            $result = Process::timeout($timeLimit + 5)
                ->path($runDir)
                ->env(['HOME' => $runDir, 'TMPDIR' => $runDir])
                ->run($command);

            $this->assertSandboxStarted($result);
        } finally {
            $cgroupUsage = $this->cgroup->release($cgroupName);
        }

        $exitCode = $result->exitCode();
        $peakRssKb = $this->peakRssKb($rssPath);

        // Issue #86 -- the hard evidence, where a cgroup was available: the
        // kernel either killed the run for its memory or held it at the
        // ceiling. Checked before peak RSS because it is the cap that was
        // actually enforced, while %M is a measurement of what the run got
        // away with.
        if ($this->cgroup->exceeded($cgroupUsage, $memoryLimit, $exitCode === 0)) {
            return [
                'success' => false,
                'verdict' => 'MLE',
                'message' => 'Memory Limit Exceeded',
                'stdout' => $result->output(),
                'stderr' => $result->errorOutput(),
            ];
        }

        // Issue #86 -- the fallback, and the only verdict available where
        // no cgroup could be delegated: measurement rather than how the
        // process happened to die. An allocation that fails and one that
        // gets killed look identical from the outside; the peak does not.
        // This is what gives every language an MLE even unprivileged,
        // including the ones no rlimit can cap.
        if ($peakRssKb !== null && $peakRssKb > $memoryLimit * 1024) {
            return [
                'success' => false,
                'verdict' => 'MLE',
                'message' => 'Memory Limit Exceeded',
                'stdout' => $result->output(),
                'stderr' => $result->errorOutput(),
            ];
        }

        // Interpret exit codes. 152 is 128+SIGXCPU, how a shell reports a
        // program that burned through its `ulimit -t` budget; 24 is the
        // same thing reported by `time`, which returns the raw signal
        // number rather than 128+n. Without both, a CPU kill lands in the
        // generic branch below as a Runtime Error instead of a Time Limit
        // Exceeded.
        if (in_array($exitCode, [137, 9, 152, 24], true)) {
            return [
                'success' => false,
                'verdict' => 'TLE',
                'message' => 'Time Limit Exceeded',
                'stdout' => $result->output(),
                'stderr' => $result->errorOutput(),
            ];
        }

        // SIGXFSZ -- the program blew the sandbox's `ulimit -f` output
        // budget. 153 from a shell, 25 raw from `time`. Reported distinctly
        // so it is not mistaken for a crash.
        if ($exitCode === 153 || $exitCode === 25) {
            return [
                'success' => false,
                'verdict' => 'RE',
                'message' => 'Runtime Error (output size limit exceeded)',
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


    protected function compareOutput(
        string $expected,
        string $actual,
        Problem $problem,
        object $language,
        ?string $inputFile = null,
        ?string $expectedOutputFile = null,
        ?string $actualOutputFile = null
    ): array {
        // Check for a custom/special-judge compare script (BOCA's compare/<lang>
        // convention), for problems with multiple valid outputs, numeric
        // tolerance, order-independent results, etc. that a plain diff can't
        // handle. Convention: `<script> <input> <expected_output> <actual_output>`,
        // exit code 0 = correct, anything else = incorrect.
        $compareScript = $problem->getCompareScriptPath($language->extension);
        if ($inputFile && $expectedOutputFile && $actualOutputFile && file_exists($compareScript)) {
            return $this->runCompareScript($compareScript, $inputFile, $expectedOutputFile, $actualOutputFile);
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

    /**
     * A problem's custom compare script (special judge).
     *
     * Also sandboxed: the script is trusted, but one of its three arguments
     * is the output the submission just produced, so it is a tool processing
     * attacker-controlled input. The run directory (where the actual output
     * lives) is the writable root; the input and expected-output files are
     * bound in read-only, individually.
     */
    protected function runCompareScript(string $script, string $inputFile, string $expectedOutputFile, string $actualOutputFile): array
    {
        $runDir = dirname($actualOutputFile);

        $command = $this->wrapWithBwrap(
            sprintf(
                'bash %s %s %s %s',
                escapeshellarg($script),
                escapeshellarg($inputFile),
                escapeshellarg($expectedOutputFile),
                escapeshellarg($actualOutputFile)
            ),
            $runDir,
            [
                'ro_binds' => [$script, $inputFile, $expectedOutputFile],
                'cpu_seconds' => $this->defaultTimeLimit * 2,
                'file_size_kb' => $this->runMaxFileKb,
            ]
        );

        $result = Process::timeout($this->defaultTimeLimit * 2)->run($command);

        $this->assertSandboxStarted($result);

        if ($result->exitCode() === 0) {
            return [
                'success' => true,
                'verdict' => 'AC',
                'message' => 'Accepted (custom compare script)',
                'stdout' => '',
                'stderr' => '',
            ];
        }

        return [
            'success' => false,
            'verdict' => 'WA',
            'message' => 'Wrong Answer (custom compare script)',
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
        ];
    }

    /**
     * A problem's custom compile script (BOCA's compile/<lang> convention).
     *
     * Sandboxed for exactly the same reason the default compile path is: the
     * script hands untrusted submitted source to a real compiler, and
     * docs/specs/49-judge-isolation.md requires the isolation to cover
     * compilation too ("aplicar também à compilação, que executa ferramentas
     * sobre fonte não confiável"). The script itself lives under the
     * application root, so it has to be bound in explicitly.
     */
    protected function runCustomScript(string $script, string $runDir, Run $run): array
    {
        $command = $this->wrapWithBwrap("bash {$script} {$run->filename}", $runDir, [
            'ro_binds' => [$script, $this->judgeRuntimePath()],
            'cpu_seconds' => $this->defaultTimeLimit * 2,
            'file_size_kb' => $this->compileMaxFileKb,
        ]);

        return $this->runCompileStep($command, $runDir, $this->compileCgroupName($run));
    }

    /**
     * Issue #115 -- run one compilation step under a resident-memory
     * ceiling.
     *
     * Shared by the default compile command and by a problem's custom
     * compile script, because a custom script runs the same toolchains over
     * the same untrusted source and there is no reason for it to be the
     * unbounded one.
     *
     * The environment below is why this is a method and not an inline
     * wrap: npm/npx (TypeScript), dotnet and go all write caches under
     * $HOME, PHP-FPM does not set HOME for the worker user, and without it
     * they fail with a permission error unrelated to the submitted code.
     * Under the sandbox these come from --setenv instead (--clearenv wipes
     * the inherited environment); they are kept here because the same code
     * path runs unsandboxed when use_bwrap is off.
     */
    protected function runCompileStep(string $command, string $runDir, string $cgroupName): array
    {
        $command = $this->cgroup->confine($command, $cgroupName, $this->compileMemoryMb);

        // Released in a finally for the same reason the run's cgroup is
        // (#86): Process::run() throws when the backstop timeout fires and
        // assertSandboxStarted() throws when bwrap never started, and those
        // are the runs most likely to have left a process inside.
        try {
            $result = Process::timeout($this->defaultTimeLimit * 2)
                ->path($runDir)
                ->env(['HOME' => $runDir, 'TMPDIR' => $runDir])
                ->run($command);

            $this->assertSandboxStarted($result);
        } finally {
            $usage = $this->cgroup->release($cgroupName);
        }

        $succeeded = $result->exitCode() === 0;

        // A compiler killed for memory is a Compilation Error, not an MLE:
        // MLE is a verdict about the contestant's algorithm, and nothing
        // has run yet. Saying so explicitly matters because the OOM killer
        // leaves a compiler's own diagnostics empty or truncated, and
        // "Compilation Error" with no message is the least useful thing a
        // judge can tell a team.
        if ($this->cgroup->exceeded($usage, $this->compileMemoryMb, $succeeded)) {
            return [
                'success' => false,
                'stdout' => $result->output(),
                'stderr' => trim($result->errorOutput()."\n"
                    ."A compilacao excedeu o limite de memoria de {$this->compileMemoryMb} MB e foi interrompida."),
                'exit_code' => $result->exitCode(),
            ];
        }

        return [
            'success' => $succeeded,
            'stdout' => $result->output(),
            'stderr' => $result->errorOutput(),
            'exit_code' => $result->exitCode(),
        ];
    }

    /**
     * Distinct from the run's cgroup name so a compilation and a run of the
     * same submission never collide -- and carrying the pid, so two workers
     * judging different runs never do either.
     */
    protected function compileCgroupName(Run $run): string
    {
        return 'compile_'.$run->id.'_'.getmypid();
    }

    protected function updateRunWithResult(Run $run, array $result): void
    {
        $this->recordVerdict($run, $result);
    }

    /**
     * Write a verdict and everything that has to happen because of it.
     *
     * Public because issue #53's judgehosts report their results over HTTP
     * rather than by being this process. A verdict that arrived from another
     * machine has to land through the same code as a local one: the
     * scoreboard recompute, the balloon Score::updateScore() raises on a
     * first solve, and the contest-log entry are not optional extras --
     * writing the columns without them produces a run that looks judged and
     * a scoreboard that disagrees with it.
     *
     * @param  array{verdict: string, message?: string|null, stdout?: string|null, stderr?: string|null}  $result
     */
    public function recordVerdict(Run $run, array $result): void
    {
        $answer = Answer::where('contest_id', $run->contest_id)
            ->where('short_name', $result['verdict'])
            ->first();

        $run->update([
            'status' => 'judged',
            'answer_id' => $answer?->id,
            'auto_judge_end' => now(),
            'auto_judge_result' => $result['message'] ?? null,
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
        // auto_judge can be overridden per language (problem_language_limits),
        // so a problem-level whereHas('auto_judge', true) alone isn't enough --
        // filter with Problem::isAutoJudgeEnabledFor() once loaded.
        return Run::where('status', 'pending')
            ->with(['problem.languageLimits', 'language'])
            ->orderBy('created_at')
            ->get()
            ->first(fn (Run $run) => $run->problem->isAutoJudgeEnabledFor($run->language));
    }
}