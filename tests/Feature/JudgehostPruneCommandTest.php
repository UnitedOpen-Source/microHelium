<?php

namespace Tests\Feature;

use App\Services\Judgehost\JudgehostClient;
use App\Services\Judgehost\WorkMaterialiser;
use Tests\TestCase;

/**
 * Issue #186 -- what a judge machine keeps, and for how long.
 *
 * The cache is why a warm judgehost is worth having, and it is also other
 * people's hidden test data on a machine in someone else's rack, which is
 * the premise of #53 rather than an edge case. Nothing removed it: no
 * command, no schedule, no bound. A partner institution kept every problem
 * of every contest it had ever judged, indefinitely, and nobody had decided
 * that.
 */
class JudgehostPruneCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = 'judgehost_prune_test_'.getmypid();
        config(['judgehost.agent.workspace' => $this->workspace]);
    }

    protected function tearDown(): void
    {
        $root = storage_path('app/'.$this->workspace);

        foreach (glob($root.'/cache/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($root.'/cache/*') ?: [] as $shard) {
            @rmdir($shard);
        }
        @rmdir($root.'/cache');
        @rmdir($root);

        parent::tearDown();
    }

    private function cached(string $digest, int $ageDays): string
    {
        $path = storage_path('app/'.$this->workspace.'/cache/'.substr($digest, 0, 2).'/'.$digest);

        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, 'entrada escondida de um problema');
        touch($path, now()->subDays($ageDays)->getTimestamp());

        return $path;
    }

    public function test_it_removes_data_nobody_has_judged_against_for_the_whole_window(): void
    {
        $stale = $this->cached(str_repeat('a', 64), ageDays: 30);
        $fresh = $this->cached(str_repeat('b', 64), ageDays: 1);

        $this->artisan('judgehost:prune', ['--days' => 7])->assertExitCode(0);

        $this->assertFileDoesNotExist($stale);
        $this->assertFileExists($fresh, 'test data used yesterday was deleted; the host just stopped being warm');
    }

    /**
     * The half that makes the age mean something. WorkMaterialiser touches a
     * cache hit, so mtime is "last used" -- without that, the test data of a
     * problem being judged all through a contest would age out from the
     * moment it first arrived and vanish mid-event.
     */
    public function test_a_file_used_today_survives_however_old_the_download_was(): void
    {
        $path = $this->cached(str_repeat('c', 64), ageDays: 90);

        // What WorkMaterialiser::cached() does on a hit.
        touch($path);

        $this->artisan('judgehost:prune', ['--days' => 7])->assertExitCode(0);

        $this->assertFileExists($path);
    }

    /**
     * The other half of the same property, at the source: that a cache hit
     * in WorkMaterialiser really does refresh the mtime the command reads.
     * Measured rather than assumed -- removing the touch leaves the file at
     * its original age (90 days in this fixture) and this fails.
     */
    public function test_the_materialiser_refreshes_the_age_when_it_reuses_a_cached_file(): void
    {
        $body = 'entrada escondida de um problema';
        $digest = hash('sha256', $body);
        $path = $this->cached($digest, ageDays: 90);
        file_put_contents($path, $body);
        touch($path, now()->subDays(90)->getTimestamp());

        $client = new class extends JudgehostClient
        {
            public function __construct() {}

            public function test_case_bytes(int $runId, int $testCaseId, string $kind): string
            {
                throw new \RuntimeException('a cache hit must not go back to the network');
            }
        };

        $materialiser = new WorkMaterialiser($client, $this->workspace);
        (new \ReflectionMethod($materialiser, 'cached'))->invoke($materialiser, 1, 1, 'input', $digest);

        $this->assertLessThan(
            60,
            time() - filemtime($path),
            'reusing cached test data did not refresh its age, so a problem judged all contest would be pruned mid-event'
        );
    }

    public function test_dry_run_removes_nothing(): void
    {
        $stale = $this->cached(str_repeat('d', 64), ageDays: 30);

        $this->artisan('judgehost:prune', ['--days' => 7, '--dry-run' => true])->assertExitCode(0);

        $this->assertFileExists($stale);
    }

    /**
     * `--days=seven` cast with (int) alone is 0, and a retention of zero
     * days deletes the entire cache of a machine mid-contest. It is refused
     * instead.
     */
    public function test_a_days_option_that_is_not_a_positive_number_is_refused(): void
    {
        $stale = $this->cached(str_repeat('e', 64), ageDays: 30);

        foreach (['seven', '0', '-3'] as $bad) {
            $this->artisan('judgehost:prune', ['--days' => $bad])->assertExitCode(1);
        }

        $this->assertFileExists($stale, 'a malformed --days wiped the cache');
    }

    public function test_it_says_so_and_succeeds_when_the_machine_has_no_cache(): void
    {
        config(['judgehost.agent.workspace' => 'judgehost_prune_nothing_here_'.getmypid()]);

        $this->artisan('judgehost:prune')
            ->expectsOutputToContain('Nada em cache')
            ->assertExitCode(0);
    }

    public function test_the_default_window_comes_from_configuration(): void
    {
        config(['judgehost.agent.cache_retention_days' => 1]);

        $twoDaysOld = $this->cached(str_repeat('f', 64), ageDays: 2);

        $this->artisan('judgehost:prune')->assertExitCode(0);

        $this->assertFileDoesNotExist($twoDaysOld);
    }
}
