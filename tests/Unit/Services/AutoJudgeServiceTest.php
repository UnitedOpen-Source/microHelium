<?php

namespace Tests\Unit\Services;

use App\Exceptions\JudgeWallClockTimeoutException;
use App\Exceptions\SandboxUnavailableException;
use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\TestCase as ModelsTestCase;
use App\Services\AutoJudgeService;
use App\Services\CgroupMemoryLimiter;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

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
            __DIR__.'/testdata/main.cpp',
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

        $service = new AutoJudgeService;
        $nextRun = $service->getNextPendingRun();
        $this->assertEquals($run->id, $nextRun->id);
    }

    public function test_handle_judging_error()
    {
        Storage::fake('local');
        $this->createTestData();
        $run = Run::first();
        Answer::factory()->create(['short_name' => 'CS', 'contest_id' => $run->contest_id]);

        // Issue #314 -- `judging` porque e de onde este metodo e alcancado.
        //
        // `handleJudgingError()` so roda a partir do `catch` de `judge()`, e
        // `judge()` marca a run `judging` antes de tentar qualquer coisa.
        // A guarda de saida nova rele a linha sob lock e recusa gravar `CS`
        // sobre uma run que nao esta mais em julgamento -- um veredito
        // manual da banca, por exemplo -- entao o estado inicial deste
        // teste passou a importar. O que ele mede continua sendo o mesmo.
        $run->update(['status' => 'judging']);

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
     * Issue #329 -- o backstop de PAREDE disparando, de verdade.
     *
     * O que chegava em `auto_judge_stderr` era a mensagem do Symfony com a
     * linha de comando inteira do `bwrap` dentro; quem investigava um `CS`
     * nao descobria dali que o que houve foi um estouro de relogio.
     *
     * Nao ha temporizacao fina aqui: sao 3 s de `sleep` contra 1 s de
     * limite, e o que se mede e QUAL excecao sai e o que ela diz -- nao
     * quando.
     */
    public function test_o_backstop_de_parede_da_compilacao_produz_mensagem_legivel()
    {
        config([
            'autojudge.use_bwrap' => false,
            'autojudge.compile_timeout' => 1,
        ]);

        $service = new class extends AutoJudgeService
        {
            public function compilaParaTeste(string $command, string $runDir): array
            {
                return $this->runCompileStep($command, $runDir, 'compile_teste_'.getmypid());
            }
        };

        try {
            $service->compilaParaTeste('sleep 3', sys_get_temp_dir());
            $this->fail('o backstop de parede nao disparou');
        } catch (JudgeWallClockTimeoutException $e) {
            $this->assertStringContainsString('1 s de tempo de parede', $e->getMessage());
            $this->assertStringContainsString('compilacao', $e->getMessage());
            $this->assertStringNotContainsString('exceeded the timeout', $e->getMessage());
            $this->assertSame(1, $e->wallSeconds);
        }
    }

    /**
     * Issue #329 -- e o limite da compilacao passou a sair de
     * `autojudge.compile_timeout`.
     *
     * A chave existia, dizia "Maximum time for compilation in seconds", e
     * nao era lida em lugar nenhum: o backstop era `time_limit * 2` escrito
     * no codigo. Quem tentasse afrouxar o limite pelo .env nao mudava nada.
     */
    public function test_o_limite_da_compilacao_vem_da_configuracao()
    {
        config([
            'autojudge.use_bwrap' => false,
            'autojudge.time_limit' => 10,
            'autojudge.compile_timeout' => 2,
        ]);

        $service = new class extends AutoJudgeService
        {
            public function compilaParaTeste(string $command, string $runDir): array
            {
                return $this->runCompileStep($command, $runDir, 'compile_teste_'.getmypid());
            }
        };

        try {
            $service->compilaParaTeste('sleep 6', sys_get_temp_dir());
            $this->fail('o backstop de parede nao disparou');
        } catch (JudgeWallClockTimeoutException $e) {
            // 2 s, e nao os 20 s que `time_limit * 2` daria.
            $this->assertSame(2, $e->wallSeconds);
        }
    }

    /**
     * Issue #329 -- o estouro de relogio continua sendo `CS`, e tem de
     * continuar: o `ulimit -t` nao disparou, entao isto nao e uma afirmacao
     * sobre o programa da equipe. O que muda e a mensagem que sobra para a
     * organizacao.
     */
    public function test_o_estouro_de_parede_vira_cs_com_mensagem_propria()
    {
        Storage::fake('local');
        $this->createTestData();
        $run = Run::first();
        Answer::factory()->create(['short_name' => 'CS', 'contest_id' => $run->contest_id]);

        $service = new class extends AutoJudgeService
        {
            protected function executeJudging(Run $run): array
            {
                throw new JudgeWallClockTimeoutException('compilacao', 30);
            }
        };

        $service->judge($run);

        $run->refresh();
        $this->assertSame('judged', $run->status);
        $this->assertSame('CS', $run->answer->short_name);
        $this->assertStringContainsString('30 s de tempo de parede', $run->auto_judge_result);
        $this->assertStringNotContainsString('Judging Error', (string) $run->auto_judge_result);
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
            // Issue #86: pinned absent, so these tests describe the
            // no-cgroup fallback whether or not the machine running them
            // has a delegated subtree. The address-space barrier is only
            // applied when there is no resident cap to be had, and the
            // judge image itself has one.
            'autojudge.cgroup_root' => '/sys/fs/cgroup/nao-delegado',
        ]);

        return new AutoJudgeService;
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

    public function test_the_runtimes_that_reject_an_rlimit_carry_their_own_memory_flag()
    {
        // Issue #102. Node, TypeScript and C# cannot take `ulimit -v` -- they
        // reserve a large virtual range at startup -- so their cap has to
        // come from the runtime itself, through the {memory} placeholder the
        // run command already supports.
        $defaults = collect(Language::getDefaultLanguages())->keyBy('extension');

        // Issue #327 -- Node e TypeScript passaram a ser invocados por
        // `judge-runtime/node/run.sh`, que acrescenta um `--stack-size`
        // derivado do `ulimit -s` do host (fixar o numero aqui trocaria o
        // `RangeError` legivel por um SIGSEGV mudo onde a pilha fosse
        // menor). O tratamento passa a ser o MESMO que o C# ao lado ja
        // tinha: o comando entrega `{memory}` ao script, e o script poe a
        // flag. O que esta linha guarda -- que o limite chega ao runtime
        // pelo `{memory}`, e nao por um `ulimit -v` -- continua valendo,
        // entao ela confere as duas metades: o placeholder no catalogo e a
        // flag no script.
        $this->assertStringContainsString('{memory}', $defaults['js_node24']['run_command']);
        $this->assertStringContainsString('{memory}', $defaults['ts']['run_command']);
        $this->assertStringContainsString(
            '--max-old-space-size="$MEMORY_MB"',
            (string) file_get_contents(base_path('resources/judge-runtime/node/run.sh')),
            'o run.sh do Node parou de aplicar o limite de memoria que o catalogo lhe entrega'
        );
        // The .NET knob is an environment variable in hex bytes, so it is
        // set inside run.sh from this argument rather than on the command.
        $this->assertStringContainsString('{memory}', $defaults['cs_dotnet']['run_command']);

        // Go is deliberately absent: GOMEMLIMIT is a soft limit by design
        // and live data walks straight past it -- measured at 640 MB under a
        // 256 MiB GOMEMLIMIT with GOGC=off. It needs cgroups (#86).
        $this->assertStringNotContainsString('{memory}', $defaults['go']['run_command']);
    }

    public function test_every_language_gets_an_address_space_barrier_with_its_own_grace()
    {
        $service = $this->sandboxingService();
        $method = new \ReflectionMethod($service, 'addressSpaceLimitKbFor');
        $limit = 256;

        // Issue #86: with no cgroup to be had, `ulimit -v` applies to
        // everything as limit + grace, because it is a crash barrier and
        // not the measurement. The grace numbers are DMOJ's shipped table;
        // Node's 1024 MB is the same figure we arrived at independently
        // when V8 refused to boot below roughly 1 GB of address space.
        foreach ([
            'c_gcc13' => 64,
            'py3' => 128,
            'go' => 768,
            'js_node24' => 1024,
            'ts' => 1024,
        ] as $extension => $graceMb) {
            $this->assertSame(
                ($limit + $graceMb) * 1024,
                $method->invoke($service, (object) ['extension' => $extension], $limit),
                "{$extension} should get {$graceMb} MB of grace"
            );
        }

        // An unknown language falls back to the default rather than to no
        // barrier at all.
        $this->assertSame(
            ($limit + 64) * 1024,
            $method->invoke($service, (object) ['extension' => 'lang-que-nao-existe'], $limit)
        );

        // And the runtimes that would need a barrier seven to fifteen times
        // the limit just to boot get none: measured on the judge image,
        // java needs 2 GB, kotlin 4 GB and C# 3 GB of address space for a
        // 256 MB problem. They keep -Xmx / DOTNET_GCHeapHardLimit, and the
        // MLE verdict comes from measured peak RSS regardless.
        foreach (['java21', 'kt', 'cs_dotnet'] as $extension) {
            $this->assertNull(
                $method->invoke($service, (object) ['extension' => $extension], $limit),
                "{$extension} must not get an address-space barrier"
            );
        }
    }

    public function test_a_cgroup_memory_cap_replaces_the_address_space_barrier_entirely()
    {
        // Issue #86. Stacking them buys nothing: the barrier sits at
        // limit+grace and memory.max at the limit, so the cgroup always
        // binds first -- measured on the judge image, a Python hog at a
        // 256 MB limit is OOM-killed at exactly 256 MB with `ulimit -v
        // 393216` and without it alike. What the barrier can still do is
        // fail a runtime that reserves address space it never touches,
        // which is why the JVM rows are null to begin with.
        config([
            'autojudge.use_bwrap' => true,
            'autojudge.bwrap_path' => '/bin/sh',
            // Any writable directory delegates as far as isAvailable() is
            // concerned; whether the kernel enforces it is
            // JudgeSandboxConfinementTest's question.
            'autojudge.cgroup_root' => sys_get_temp_dir(),
        ]);

        $service = new AutoJudgeService;
        $method = new \ReflectionMethod($service, 'addressSpaceLimitKbFor');

        foreach (['c_gcc13', 'py3', 'go', 'js_node24', 'java21', 'kt'] as $extension) {
            $this->assertNull(
                $method->invoke($service, (object) ['extension' => $extension], 256),
                "{$extension} must lose its address-space barrier once a resident cap is available"
            );
        }
    }

    /**
     * Issue #86 -- the per-run cgroup must be released even when the run
     * blows up, because the two ways out of executeProgram() that are not a
     * return both throw: Process::run() raises ProcessTimedOutException
     * when the backstop timeout fires, and assertSandboxStarted() raises
     * when bwrap never started.
     *
     * Those are exactly the runs most likely to still have a process inside
     * the cgroup, and a cgroup with a live process in it is holding the
     * memory the cap exists to bound. The name carries the pid, so
     * create()'s reuse of a stale directory never gets a second chance at
     * it once the worker is recycled.
     */
    public function test_the_cgroup_is_released_even_when_the_run_throws()
    {
        config([
            'autojudge.use_bwrap' => true,
            'autojudge.bwrap_path' => '/bin/sh',
        ]);

        // Every process this service starts -- the run and the sandbox
        // probe assertSandboxStarted() consults -- reports that bwrap could
        // not start, which is what makes it throw.
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'bwrap: Creating new namespace failed: Operation not permitted', exitCode: 1),
        ]);

        $limiter = new class extends CgroupMemoryLimiter
        {
            public array $confined = [];

            public array $released = [];

            public function __construct()
            {
                parent::__construct('/sys/fs/cgroup/irrelevante');
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function confine(string $command, string $name, int $memoryLimitMb): string
            {
                $this->confined[] = $name;

                return $command;
            }

            public function release(string $name): ?array
            {
                $this->released[] = $name;

                return null;
            }
        };

        $service = new AutoJudgeService($limiter);

        $run = $this->runFixture();
        $runDir = sys_get_temp_dir().'/mh_leak_'.getmypid();
        @mkdir($runDir, 0755, true);

        try {
            $this->callProtected($service, 'executeProgram', [$run, $runDir, '/dev/null', $runDir.'/out.txt']);
            $this->fail('executeProgram was expected to throw when the sandbox cannot start.');
        } catch (SandboxUnavailableException) {
            // The point of the test is what happened on the way out.
        } finally {
            @unlink($runDir.'/out.txt');
            foreach (glob($runDir.'/*') ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($runDir);
        }

        $this->assertNotEmpty($limiter->confined, 'The run should have been confined to a cgroup at all.');
        $this->assertSame(
            $limiter->confined,
            $limiter->released,
            'Every cgroup this run created should have been released, including on the throwing path.'
        );
    }

    public function test_the_peak_memory_measurement_keeps_its_output_away_from_the_submissions()
    {
        $service = $this->sandboxingService();
        $method = new \ReflectionMethod($service, 'withPeakRssMeasurement');

        config(['autojudge.rss_time_path' => '/bin/sh']);
        $service = $this->sandboxingService();

        $wrapped = $method->invoke($service, 'prog < in > out 2>&1', '/tmp/run_1/.mhrss');

        // `time -f` writes to its own stderr, and the submission's stderr is
        // already merged into the output file -- so the measured command is
        // re-nested and time's stream is redirected somewhere else entirely.
        //
        // Issue #196 acrescentou tempo ao formato: `%e` de parede, `%U`+`%S`
        // de CPU. O assunto deste teste continua sendo PARA ONDE a medicao
        // vai, e nao quais campos sao pedidos -- que campos sao pedidos e o
        // que JudgeMeasurementFormatTest fixa, derivando a entrada do parser
        // a partir do proprio formato para que os dois nao possam divergir.
        $this->assertStringContainsString("-f 'MHRSS %M %e %U %S'", $wrapped);
        $this->assertStringContainsString("2> '/tmp/run_1/.mhrss'", $wrapped);
        $this->assertStringContainsString("bash -c 'prog < in > out 2>&1'", $wrapped);
    }

    public function test_a_missing_measurement_binary_leaves_the_command_alone()
    {
        config(['autojudge.rss_time_path' => '/no/such/time']);
        $service = $this->sandboxingService();
        $method = new \ReflectionMethod($service, 'withPeakRssMeasurement');

        // A missing measurement must not stop a contest.
        $this->assertSame('prog < in > out', $method->invoke($service, 'prog < in > out', '/tmp/x'));
    }

    public function test_sandbox_applies_a_memory_rlimit_when_one_is_given()
    {
        $command = $this->sandboxingService()->wrapWithBwrap('echo hello', '/tmp/run_1', [
            'memory_kb' => 262144,
        ]);

        $this->assertStringContainsString("bash -c 'ulimit -v 262144; echo hello'", $command);
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

        $this->assertSame('echo hello', (new AutoJudgeService)->wrapWithBwrap('echo hello', '/tmp/run_1'));
    }

    public function test_missing_bwrap_binary_throws_runtime_exception()
    {
        config([
            'autojudge.use_bwrap' => true,
            'autojudge.bwrap_path' => '/non/existent/bwrap',
        ]);
        $service = new AutoJudgeService;

        $this->expectException(SandboxUnavailableException::class);
        $this->expectExceptionMessage('Mandatory judge sandbox binary (bwrap) not found');

        $service->wrapWithBwrap('echo hello', '/tmp/run_1');
    }

    public function test_build_compile_command()
    {
        $language = (object) [
            'compile_command' => 'g++ {source} -o {output}',
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
        if (! is_dir($runDir)) {
            mkdir($runDir, 0755, true);
        }

        $this->service->cleanup($run);

        $this->assertFalse(Storage::disk('local')->exists("app/workdir/runs/{$run->id}"));
    }

    /**
     * A run with just enough attached to it for executeProgram() to build a
     * command: a problem for the limits and a language for the command.
     */
    private function runFixture(): Run
    {
        $contest = Contest::factory()->create();
        $user = User::factory()->create();
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create([
            'contest_id' => $contest->id,
            'compile_command' => 'g++',
            'run_command' => './a.out',
        ]);

        return Run::factory()->create([
            'user_id' => $user->user_id,
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
        ]);
    }

    private function callProtected(object $target, string $method, array $arguments): mixed
    {
        return (new \ReflectionMethod($target, $method))->invokeArgs($target, $arguments);
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
            __DIR__.'/testdata/main.cpp',
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
            __DIR__.'/testdata/1.in',
            '1.in'
        ), '1.in');
        $outputPath = Storage::putFileAs("problems/{$problem->id}", new UploadedFile(
            __DIR__.'/testdata/1.out',
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
