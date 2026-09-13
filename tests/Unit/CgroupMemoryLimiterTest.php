<?php

namespace Tests\Unit;

use App\Services\CgroupMemoryLimiter;
use Tests\TestCase;

/**
 * Issue #86 -- the parts of the cgroup memory cap that can be decided
 * without a delegated cgroup subtree: the degradation path (which is what
 * every unprivileged deployment gets) and the verdict rule.
 *
 * A plain directory stands in for the delegated root here. It is enough to
 * pin the shape of what gets executed and how the counters are read;
 * whether the kernel actually enforces the cap is
 * JudgeSandboxConfinementTest's question, against a real one.
 */
class CgroupMemoryLimiterTest extends TestCase
{
    private string $fakeRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeRoot = sys_get_temp_dir().'/mh_cgroup_'.getmypid();
        @mkdir($this->fakeRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->fakeRoot.'/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->fakeRoot.'/*') ?: [] as $dir) {
            is_dir($dir) ? @rmdir($dir) : @unlink($dir);
        }
        @rmdir($this->fakeRoot);

        parent::tearDown();
    }

    public function test_it_is_unavailable_when_no_subtree_was_delegated()
    {
        $limiter = new CgroupMemoryLimiter('/sys/fs/cgroup/definitely-not-delegated');

        $this->assertFalse($limiter->isAvailable());
    }

    public function test_an_absent_subtree_leaves_the_command_untouched()
    {
        // The contract the whole feature rests on: judging must never stop
        // because cgroups are unavailable.
        $limiter = new CgroupMemoryLimiter('/sys/fs/cgroup/definitely-not-delegated');

        $this->assertSame('true', $limiter->confine('true', 'run_1_2', 256));
        $this->assertNull($limiter->release('run_1_2'));
    }

    public function test_it_joins_the_cgroup_before_exec_so_children_inherit_it()
    {
        $limiter = new CgroupMemoryLimiter($this->fakeRoot);

        $command = $limiter->confine('/usr/bin/bwrap --unshare-all true', 'run_7_9', 256);

        // The `exec` is the point: the shell that wrote its own pid into
        // cgroup.procs becomes the payload, so bwrap and everything under
        // it are already members when they start.
        $this->assertStringContainsString($this->fakeRoot.'/run_7_9/cgroup.procs', $command);
        $this->assertStringContainsString('exec /usr/bin/bwrap --unshare-all true', $command);
        $this->assertMatchesRegularExpression('/^sh -c /', $command);
    }

    public function test_it_writes_the_limit_in_bytes()
    {
        $limiter = new CgroupMemoryLimiter($this->fakeRoot);

        $limiter->confine('true', 'run_7_9', 256);

        $this->assertSame('268435456', file_get_contents($this->fakeRoot.'/run_7_9/memory.max'));
        $this->assertSame('0', file_get_contents($this->fakeRoot.'/run_7_9/memory.swap.max'));
    }

    public function test_a_name_that_is_not_a_plain_identifier_is_refused()
    {
        $limiter = new CgroupMemoryLimiter($this->fakeRoot);

        $this->assertSame('true', $limiter->confine('true', '../escape', 256));
        $this->assertSame('true', $limiter->confine('true', 'run 1; rm -rf /', 256));
        $this->assertDirectoryDoesNotExist($this->fakeRoot.'/../escape');
    }

    public function test_release_reads_the_kernel_counters()
    {
        // Removal itself needs a real cgroupfs, where rmdir(2) takes a
        // directory that still holds the kernel's own control files:
        // JudgeSandboxConfinementTest covers that against a real one.
        $limiter = new CgroupMemoryLimiter($this->fakeRoot);

        $limiter->confine('true', 'run_7_9', 256);
        file_put_contents($this->fakeRoot.'/run_7_9/memory.peak', "268435456\n");
        file_put_contents(
            $this->fakeRoot.'/run_7_9/memory.events',
            "low 0\nhigh 0\nmax 35\noom 1\noom_kill 1\noom_group_kill 0\n"
        );

        $this->assertSame(
            ['peak_bytes' => 268435456, 'oom_kills' => 1],
            $limiter->release('run_7_9')
        );
    }

    public function test_release_survives_a_kernel_too_old_for_memory_peak()
    {
        // memory.peak is 5.19 and up. The oom_kill counter is the
        // conclusive half of the verdict and has always been there.
        $limiter = new CgroupMemoryLimiter($this->fakeRoot);

        $limiter->confine('true', 'run_7_9', 256);
        file_put_contents($this->fakeRoot.'/run_7_9/memory.events', "oom_kill 2\n");

        $this->assertSame(
            ['peak_bytes' => null, 'oom_kills' => 2],
            $limiter->release('run_7_9')
        );
    }

    public function test_an_oom_kill_is_conclusive_on_its_own()
    {
        $limiter = new CgroupMemoryLimiter($this->fakeRoot);

        $this->assertTrue($limiter->exceeded(
            ['peak_bytes' => 1024, 'oom_kills' => 1],
            256,
            succeeded: false
        ));
    }

    public function test_reaching_the_ceiling_counts_even_without_an_oom_kill()
    {
        // memory.max does not guarantee a kill: an allocation the kernel
        // refuses can just return NULL, which from the outside is any other
        // failure. The peak is what tells the two apart.
        $limiter = new CgroupMemoryLimiter($this->fakeRoot);

        $this->assertTrue($limiter->exceeded(
            ['peak_bytes' => 256 * 1024 * 1024, 'oom_kills' => 0],
            256,
            succeeded: false
        ));
    }

    public function test_a_run_that_succeeded_is_never_called_over_the_limit_on_the_peak_alone()
    {
        // memory.peak counts page cache too, so a run that only read a
        // large input can touch the ceiling without being denied a byte.
        // It produced the right answer and exited zero: that is not an MLE.
        $limiter = new CgroupMemoryLimiter($this->fakeRoot);

        $this->assertFalse($limiter->exceeded(
            ['peak_bytes' => 256 * 1024 * 1024, 'oom_kills' => 0],
            256,
            succeeded: true
        ));
    }

    public function test_a_run_below_the_ceiling_is_not_over_the_limit()
    {
        $limiter = new CgroupMemoryLimiter($this->fakeRoot);

        $this->assertFalse($limiter->exceeded(
            ['peak_bytes' => 200 * 1024 * 1024, 'oom_kills' => 0],
            256,
            succeeded: false
        ));
    }

    public function test_no_measurement_is_not_a_memory_verdict()
    {
        $limiter = new CgroupMemoryLimiter($this->fakeRoot);

        $this->assertFalse($limiter->exceeded(null, 256, succeeded: false));
        $this->assertFalse($limiter->exceeded(['peak_bytes' => null, 'oom_kills' => 0], 256, succeeded: false));
    }
}
