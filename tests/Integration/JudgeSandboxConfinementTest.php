<?php

namespace Tests\Integration;

use App\Models\Run;
use App\Services\AutoJudgeService;
use App\Services\CgroupMemoryLimiter;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\RequiresJudgeSandbox;
use Tests\TestCase;

/**
 * Issue #49 -- the confinement acceptance criteria from
 * docs/specs/49-judge-isolation.md, exercised against a real bubblewrap:
 *
 *   "não ler arquivo sentinela fora da tentativa, não alterar imagem/host,
 *    não acessar arquivo de outro run, não abrir rede, limites de
 *    memória/processos/disco/tempo aplicados"
 *
 * Deliberately toolchain-free -- it drives the sandbox with shell builtins
 * instead of the language images -- so it is the part of #49 that can run
 * anywhere bwrap exists, including CI, without building the multi-gigabyte
 * judge image. tests/E2E/JudgeSandboxIsolationTest.php covers the same
 * ground per language once that image is available.
 */
class JudgeSandboxConfinementTest extends TestCase
{
    use RequiresJudgeSandbox;

    private AutoJudgeService $service;

    private string $runDir;

    private string $sentinel;

    protected function setUp(): void
    {
        $this->skipUnlessJudgeSandboxAvailable();

        parent::setUp();

        $this->enableJudgeSandbox();

        $this->service = new AutoJudgeService;

        $this->runDir = sys_get_temp_dir().'/autojudge_confinement_'.getmypid();
        @mkdir($this->runDir, 0755, true);

        // The sentinel lives under the application root, next to .env and
        // every other run's directory -- exactly the region the sandbox must
        // not expose.
        $this->sentinel = base_path('storage/app/.sandbox-sentinel');
        file_put_contents($this->sentinel, "SENTINEL-SECRET\n");
    }

    protected function tearDown(): void
    {
        // setUp() skips before initialising anything when bwrap is missing.
        if (isset($this->sentinel)) {
            @unlink($this->sentinel);
        }

        if (isset($this->runDir)) {
            foreach (glob($this->runDir.'/*') ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($this->runDir);
        }

        parent::tearDown();
    }

    public function test_sandboxed_code_cannot_read_a_file_outside_the_run_directory()
    {
        $result = $this->sandbox('cat '.escapeshellarg($this->sentinel));

        $this->assertNotSame(0, $result->exitCode());
        $this->assertStringNotContainsString('SENTINEL-SECRET', $result->output());
        $this->assertStringNotContainsString('SENTINEL-SECRET', $result->errorOutput());
    }

    public function test_sandboxed_code_cannot_read_the_application_env_file()
    {
        $env = base_path('.env');
        if (! file_exists($env)) {
            $this->markTestSkipped('no .env in this checkout');
        }

        $result = $this->sandbox('cat '.escapeshellarg($env));

        $this->assertNotSame(0, $result->exitCode());
        $this->assertStringNotContainsString('APP_KEY', $result->output());
    }

    public function test_sandboxed_code_cannot_list_the_application_root()
    {
        $result = $this->sandbox('ls '.escapeshellarg(base_path()));

        $this->assertNotSame(0, $result->exitCode());
    }

    public function test_sandboxed_code_cannot_write_outside_the_run_directory()
    {
        $target = base_path('storage/app/.sandbox-escape');
        @unlink($target);

        $this->sandbox('echo hacked > '.escapeshellarg($target));

        $this->assertFileDoesNotExist($target);
    }

    public function test_sandboxed_code_can_read_and_write_inside_the_run_directory()
    {
        $result = $this->sandbox('echo ok > artifact.txt && cat artifact.txt');

        $this->assertSame(0, $result->exitCode(), $result->errorOutput());
        $this->assertSame("ok\n", $result->output());
        $this->assertFileExists($this->runDir.'/artifact.txt');
    }

    public function test_an_explicitly_bound_file_is_readable_but_not_writable()
    {
        $readable = $this->sandbox(
            'cat '.escapeshellarg($this->sentinel),
            ['ro_binds' => [$this->sentinel]]
        );

        $this->assertSame(0, $readable->exitCode(), $readable->errorOutput());
        $this->assertStringContainsString('SENTINEL-SECRET', $readable->output());

        $this->sandbox(
            'echo overwritten > '.escapeshellarg($this->sentinel),
            ['ro_binds' => [$this->sentinel]]
        );

        $this->assertSame("SENTINEL-SECRET\n", file_get_contents($this->sentinel));
    }

    public function test_sandbox_tmp_is_private_to_the_run()
    {
        $this->sandbox('echo leaked > /tmp/sandbox_tmp_probe.txt');

        $this->assertFileDoesNotExist('/tmp/sandbox_tmp_probe.txt');
    }

    /**
     * Issue #335 -- a evidencia e a TABELA DE ROTAS, e nao o NOME das
     * interfaces.
     *
     * Este caso conferia que `eth0` nao aparecia em `/proc/net/dev`. Duas
     * coisas estao erradas nisso, e as duas foram medidas. A lista de
     * interfaces de um namespace de rede novo NAO e so `lo`: todo kernel
     * com os modulos de tunel carregados (`ipip`, `sit`, `ip6_tunnel`,
     * `ip6_gre`) registra um device de fallback em cada namespace novo --
     * `tunl0 gre0 gretap0 erspan0 ip_vti0 ip6_vti0 sit0 ip6tnl0 ip6gre0`,
     * com zero rotas e zero enderecos. E `eth0` e apenas o nome que o
     * Docker da ao veth: um vazamento por um device com outro nome passava
     * batido, e era verde sobre um mecanismo que nao media o que dizia.
     *
     * A tabela de rotas mede o que importa. Um namespace de rede novo nao
     * tem rota nenhuma; um host de verdade sempre tem, mesmo sem internet.
     * Medido nesta imagem: 0 rotas com `--unshare-all`, 2 com `--share-net`.
     */
    public function test_sandboxed_code_has_no_route_out_of_its_network_namespace()
    {
        $result = $this->sandbox("cat /proc/net/dev; echo ROTAS=$(awk 'NR>1' /proc/net/route | wc -l)");

        $this->assertSame(0, $result->exitCode(), $result->errorOutput());
        $this->assertStringContainsString('lo:', $result->output());
        $this->assertStringContainsString('ROTAS=0', $result->output(), $result->output());
    }

    public function test_sandboxed_code_cannot_see_host_processes()
    {
        $result = $this->sandbox('ls -d /proc/[0-9]* | wc -l');

        $this->assertSame(0, $result->exitCode(), $result->errorOutput());
        $this->assertLessThan(10, (int) trim($result->output()));
    }

    public function test_the_memory_limit_actually_stops_an_allocating_program()
    {
        // Issue #86. Without a cap a program allocates until the machine
        // says no: measured in the judge image, a malloc loop passed 4 GB
        // and kept going.
        $program = 'python3 -c "'
            ."a=[]\nfor _ in range(40): a.append(bytearray(16<<20))\nprint('grew')"
            .'" 2>&1';

        $unbounded = $this->sandbox($program, ['cpu_seconds' => 20]);
        $this->assertStringContainsString('grew', $unbounded->output(), 'uncapped, the allocation should succeed');

        $capped = $this->sandbox($program, ['cpu_seconds' => 20, 'memory_kb' => 262144]);

        $this->assertStringNotContainsString('grew', $capped->output());
        $this->assertStringContainsString('MemoryError', $capped->output());
    }

    public function test_a_cgroup_memory_cap_stops_an_allocating_program_at_the_limit()
    {
        // Issue #86 -- the difference between this and the `ulimit -v` case
        // above: that one caps address space and hopes the allocation fails
        // somewhere sensible, this one is a resident cap the kernel
        // enforces, and the program never gets a byte past it.
        $limiter = new CgroupMemoryLimiter;

        if (! $limiter->isAvailable()) {
            $this->markTestSkipped('no delegated cgroup v2 subtree (is the judge container privileged?)');
        }

        $name = 'confinement_'.getmypid();
        $program = 'python3 -c "'
            ."a=[]\nfor _ in range(40): a.append(bytearray(16<<20))\nprint('grew')"
            .'" 2>&1';

        $command = $limiter->confine(
            $this->service->wrapWithBwrap($program, $this->runDir, ['cpu_seconds' => 20]),
            $name,
            128
        );

        $result = Process::timeout(60)->path($this->runDir)->run($command);
        $usage = $limiter->release($name);

        $this->assertStringNotContainsString('grew', $result->output());
        $this->assertNotNull($usage);
        $this->assertTrue(
            $limiter->exceeded($usage, 128, $result->exitCode() === 0),
            'the run should have been judged over its memory limit, got '.json_encode($usage)
        );
        $this->assertLessThanOrEqual(
            128 * 1024 * 1024,
            $usage['peak_bytes'],
            'the cap is a cap: nothing should have been resident above it'
        );
    }

    public function test_a_cgroup_memory_cap_leaves_a_well_behaved_program_alone()
    {
        $limiter = new CgroupMemoryLimiter;

        if (! $limiter->isAvailable()) {
            $this->markTestSkipped('no delegated cgroup v2 subtree (is the judge container privileged?)');
        }

        $name = 'confinement_ok_'.getmypid();

        $command = $limiter->confine(
            $this->service->wrapWithBwrap('echo ok', $this->runDir, ['cpu_seconds' => 20]),
            $name,
            128
        );

        $result = Process::timeout(60)->path($this->runDir)->run($command);
        $usage = $limiter->release($name);

        $this->assertSame(0, $result->exitCode(), $result->errorOutput());
        $this->assertSame("ok\n", $result->output());
        $this->assertFalse($limiter->exceeded($usage, 128, true));
    }

    public function test_the_cgroup_is_removed_after_the_run()
    {
        // A judge that leaked a cgroup per run would fill /sys/fs/cgroup
        // over a contest.
        $limiter = new CgroupMemoryLimiter;

        if (! $limiter->isAvailable()) {
            $this->markTestSkipped('no delegated cgroup v2 subtree (is the judge container privileged?)');
        }

        $root = config('autojudge.cgroup_root');
        $name = 'confinement_gc_'.getmypid();

        $command = $limiter->confine(
            $this->service->wrapWithBwrap('echo ok', $this->runDir, []),
            $name,
            128
        );

        Process::timeout(60)->path($this->runDir)->run($command);

        $this->assertDirectoryExists($root.'/'.$name);

        $limiter->release($name);

        $this->assertDirectoryDoesNotExist($root.'/'.$name);
    }

    public function test_sandboxed_code_cannot_reach_the_cgroup_hierarchy_at_all()
    {
        // Issue #86. Submitted code runs as the same uid 1000 the subtree
        // is delegated to, so if /sys were visible inside the sandbox a
        // submission could raise its own memory.max or simply write its pid
        // into another cgroup and walk out of the cap. It is not: /sys is
        // absent from autojudge.sandbox_paths and bwrap mounts nothing it
        // was not asked to.
        $result = $this->sandbox('ls /sys/fs/cgroup; echo "exit:$?"');

        $this->assertStringNotContainsString('cgroup.procs', $result->output());
        $this->assertStringContainsString('exit:', $result->output());
        $this->assertStringNotContainsString('exit:0', $result->output());
    }

    public function test_the_delegated_subtree_does_not_expose_its_own_resource_controls()
    {
        // Kernel delegation containment: uid 1000 owns the subtree's
        // cgroup.procs so it can move processes into its children, but NOT
        // the parent's memory.max -- otherwise submitted code could raise
        // the ceiling it is being capped under.
        $limiter = new CgroupMemoryLimiter;

        if (! $limiter->isAvailable()) {
            $this->markTestSkipped('no delegated cgroup v2 subtree (is the judge container privileged?)');
        }

        $root = config('autojudge.cgroup_root');

        $this->assertFalse(
            @file_put_contents($root.'/memory.max', (string) (64 << 20)) !== false,
            'the judge user must not be able to write the delegated root\'s own memory.max'
        );
    }

    public function test_cpu_and_file_size_limits_are_applied_inside_the_sandbox()
    {
        $result = $this->sandbox('ulimit -t; ulimit -f', [
            'cpu_seconds' => 7,
            'file_size_kb' => 512,
        ]);

        $this->assertSame(0, $result->exitCode(), $result->errorOutput());
        $this->assertSame(['7', '512'], array_values(array_filter(
            array_map('trim', explode("\n", $result->output())),
            fn ($line) => $line !== ''
        )));
    }

    /**
     * Issue #115 -- compilation gets a resident-memory ceiling too.
     *
     * #86 capped the RUN and left this step unbounded, which is backwards:
     * compilation is the one that runs toolchains over untrusted source.
     * A C++ submission can blow a compiler up from the source alone --
     * template metaprogramming, recursive macros, an enormous array
     * literal -- and before this it took the whole judge machine with it.
     *
     * Driven through runCompileStep() with a shell that allocates, rather
     * than through a real compiler, for the same reason the rest of this
     * file is toolchain-free: it has to run anywhere bwrap and cgroups
     * exist, without the multi-gigabyte judge image.
     */
    public function test_a_compilation_that_runs_away_on_memory_is_stopped()
    {
        $limiter = new CgroupMemoryLimiter;

        if (! $limiter->isAvailable()) {
            $this->markTestSkipped('no delegated cgroup v2 subtree (is the judge container privileged?)');
        }

        config(['autojudge.compile_memory_mb' => 64]);
        $service = new AutoJudgeService;

        // Doubling a shell variable, because the cap is on RESIDENT
        // memory and the string has to actually be held: a pipeline of the
        // same total size streams through in constant memory and is
        // correctly not stopped. 2^28 bytes, so it crosses 64 MB well
        // before it finishes.
        $hog = $this->service->wrapWithBwrap(
            'x=y; i=0; while [ $i -lt 28 ]; do x=$x$x; i=$((i+1)); done; echo ${#x}',
            $this->runDir,
            ['cpu_seconds' => 20]
        );

        $result = $this->callProtected($service, 'runCompileStep', [
            $hog, $this->runDir, 'compile_test_'.getmypid(),
        ]);

        $this->assertFalse($result['success'], 'A compilation over the ceiling must not be reported as successful.');

        // The team has to be told why. An OOM kill leaves the compiler's
        // own diagnostics empty, and "Compilation Error" with no message is
        // the least useful thing a judge can say.
        $this->assertStringContainsString('limite de memoria', $result['stderr']);
        $this->assertStringContainsString('64 MB', $result['stderr']);
    }

    public function test_a_compilation_within_the_ceiling_is_left_alone()
    {
        $limiter = new CgroupMemoryLimiter;

        if (! $limiter->isAvailable()) {
            $this->markTestSkipped('no delegated cgroup v2 subtree (is the judge container privileged?)');
        }

        // The other half of the pair: a ceiling that fails honest
        // compilations is worse than no ceiling, because it rejects correct
        // submissions with a verdict the team cannot act on.
        config(['autojudge.compile_memory_mb' => 64]);
        $service = new AutoJudgeService;

        $result = $this->callProtected($service, 'runCompileStep', [
            $this->service->wrapWithBwrap('echo compiled', $this->runDir, ['cpu_seconds' => 20]),
            $this->runDir,
            'compile_ok_'.getmypid(),
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('limite de memoria', $result['stderr']);
    }

    /**
     * The cgroup a compilation runs in must not be the one its run uses,
     * or releasing the first would tear down the second.
     */
    public function test_compilation_and_execution_do_not_share_a_cgroup()
    {
        $service = new AutoJudgeService;

        $run = new Run;
        $run->id = 4242;

        $this->assertSame(
            'compile_4242_'.getmypid(),
            $this->callProtected($service, 'compileCgroupName', [$run])
        );
    }

    private function callProtected(object $target, string $method, array $arguments): mixed
    {
        return (new \ReflectionMethod($target, $method))->invokeArgs($target, $arguments);
    }

    private function sandbox(string $command, array $options = []): ProcessResult
    {
        return Process::timeout(30)
            ->path($this->runDir)
            ->run($this->service->wrapWithBwrap($command, $this->runDir, $options));
    }
}
