<?php

namespace Tests\Integration;

use App\Services\AutoJudgeService;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
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
    private AutoJudgeService $service;
    private string $runDir;
    private string $sentinel;

    protected function setUp(): void
    {
        // Both skip checks run BEFORE parent::setUp(): skipping after the
        // Laravel application has booted leaves its error/exception handlers
        // installed (tearDown never runs), which PHPUnit reports as risky --
        // and phpunit.xml sets failOnRisky.
        $bwrap = getenv('AUTOJUDGE_BWRAP_PATH') ?: '/usr/bin/bwrap';

        if (!is_executable($bwrap)) {
            $this->markTestSkipped("bubblewrap is not installed at {$bwrap}");
        }

        // bwrap can be installed and still be unable to build a sandbox (no
        // user namespaces in this container). Skip loudly instead of letting
        // every assertion below pass for the wrong reason.
        $probeBinds = '';
        foreach (['/usr', '/bin', '/sbin', '/lib', '/lib64'] as $probePath) {
            if (file_exists($probePath)) {
                $probeBinds .= '--ro-bind ' . escapeshellarg($probePath) . ' ' . escapeshellarg($probePath) . ' ';
            }
        }

        exec(escapeshellarg($bwrap) . ' --unshare-all --die-with-parent ' . $probeBinds . '--proc /proc --dev /dev /bin/sh -c "exit 0" 2>/dev/null', $ignored, $probeExit);
        if ($probeExit !== 0) {
            $this->markTestSkipped('bubblewrap cannot create a sandbox here (user namespaces unavailable?)');
        }

        parent::setUp();

        config(['autojudge.use_bwrap' => true]);

        $this->service = new AutoJudgeService();

        $this->runDir = sys_get_temp_dir() . '/autojudge_confinement_' . getmypid();
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
            foreach (glob($this->runDir . '/*') ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($this->runDir);
        }

        parent::tearDown();
    }

    public function test_sandboxed_code_cannot_read_a_file_outside_the_run_directory()
    {
        $result = $this->sandbox('cat ' . escapeshellarg($this->sentinel));

        $this->assertNotSame(0, $result->exitCode());
        $this->assertStringNotContainsString('SENTINEL-SECRET', $result->output());
        $this->assertStringNotContainsString('SENTINEL-SECRET', $result->errorOutput());
    }

    public function test_sandboxed_code_cannot_read_the_application_env_file()
    {
        $env = base_path('.env');
        if (!file_exists($env)) {
            $this->markTestSkipped('no .env in this checkout');
        }

        $result = $this->sandbox('cat ' . escapeshellarg($env));

        $this->assertNotSame(0, $result->exitCode());
        $this->assertStringNotContainsString('APP_KEY', $result->output());
    }

    public function test_sandboxed_code_cannot_list_the_application_root()
    {
        $result = $this->sandbox('ls ' . escapeshellarg(base_path()));

        $this->assertNotSame(0, $result->exitCode());
    }

    public function test_sandboxed_code_cannot_write_outside_the_run_directory()
    {
        $target = base_path('storage/app/.sandbox-escape');
        @unlink($target);

        $this->sandbox('echo hacked > ' . escapeshellarg($target));

        $this->assertFileDoesNotExist($target);
    }

    public function test_sandboxed_code_can_read_and_write_inside_the_run_directory()
    {
        $result = $this->sandbox('echo ok > artifact.txt && cat artifact.txt');

        $this->assertSame(0, $result->exitCode(), $result->errorOutput());
        $this->assertSame("ok\n", $result->output());
        $this->assertFileExists($this->runDir . '/artifact.txt');
    }

    public function test_an_explicitly_bound_file_is_readable_but_not_writable()
    {
        $readable = $this->sandbox(
            'cat ' . escapeshellarg($this->sentinel),
            ['ro_binds' => [$this->sentinel]]
        );

        $this->assertSame(0, $readable->exitCode(), $readable->errorOutput());
        $this->assertStringContainsString('SENTINEL-SECRET', $readable->output());

        $this->sandbox(
            'echo overwritten > ' . escapeshellarg($this->sentinel),
            ['ro_binds' => [$this->sentinel]]
        );

        $this->assertSame("SENTINEL-SECRET\n", file_get_contents($this->sentinel));
    }

    public function test_sandbox_tmp_is_private_to_the_run()
    {
        $this->sandbox('echo leaked > /tmp/sandbox_tmp_probe.txt');

        $this->assertFileDoesNotExist('/tmp/sandbox_tmp_probe.txt');
    }

    public function test_sandboxed_code_has_no_network_interface_beyond_loopback()
    {
        $result = $this->sandbox('cat /proc/net/dev');

        $this->assertSame(0, $result->exitCode(), $result->errorOutput());
        $this->assertStringContainsString('lo:', $result->output());
        $this->assertStringNotContainsString('eth0', $result->output());
    }

    public function test_sandboxed_code_cannot_see_host_processes()
    {
        $result = $this->sandbox('ls -d /proc/[0-9]* | wc -l');

        $this->assertSame(0, $result->exitCode(), $result->errorOutput());
        $this->assertLessThan(10, (int) trim($result->output()));
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

    private function sandbox(string $command, array $options = []): ProcessResult
    {
        return Process::timeout(30)
            ->path($this->runDir)
            ->run($this->service->wrapWithBwrap($command, $this->runDir, $options));
    }
}
