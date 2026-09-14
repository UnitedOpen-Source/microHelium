<?php

namespace Tests\E2E;

use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Symfony\Component\Process\Process;

/**
 * Issue #53/#116 -- the judgehost protocol over an actual socket.
 *
 * Every other judgehost test fakes the HTTP client, dispatching requests
 * into this application's own router. That catches a great deal and it
 * cannot catch a shape mismatch between what the client returns and what
 * its caller expects, because both halves run in one process with one set
 * of types.
 *
 * It missed one. MachineCapabilities read properties off the associative
 * arrays JudgehostClient::languages() returns, so the agent declared no
 * capabilities at all -- silently, since "declared nothing" legitimately
 * means "can judge anything". Every unit test passed; they all built their
 * input with (object) casts. One real run found it immediately.
 *
 * So this starts a real server, points a real agent at it over TCP, and
 * gives the agent its OWN EMPTY DATABASE: if judging needed the contest
 * database, it could not possibly pass, which is the property that lets a
 * judgehost live in someone else's rack.
 *
 * Deliberately not extending Tests\TestCase: that boots the app with an
 * in-memory database inside a transaction, and no other process can see
 * either.
 */
class JudgehostOverRealHttpTest extends BaseTestCase
{
    private string $workspace;

    private string $serverDatabase;

    private string $agentDatabase;

    private ?Process $server = null;

    private int $port;

    /**
     * Laravel loads .env.{APP_ENV} when it exists, which is the only way to
     * be sure of this configuration: the repository's own .env points the
     * cache at Redis, and whether a process environment variable wins over
     * it turned out to differ between a developer machine and the judge
     * image. A file the test writes and removes leaves nothing to find out.
     */
    private string $environmentName;

    protected function setUp(): void
    {
        parent::setUp();

        // Before anything is initialised: skipping here means tearDown()
        // runs against an object whose typed properties were never set.
        $this->skipWithoutGnuTime();

        $this->workspace = sys_get_temp_dir().'/mh_e2e_'.getmypid().'_'.random_int(1000, 9999);
        @mkdir($this->workspace, 0755, true);

        $this->serverDatabase = $this->workspace.'/server.sqlite';
        $this->agentDatabase = $this->workspace.'/agent-empty.sqlite';
        touch($this->serverDatabase);
        touch($this->agentDatabase);

        $this->port = $this->freePort();

        $this->environmentName = 'e2e'.getmypid();
        $this->writeEnvironmentFile();
    }

    protected function tearDown(): void
    {
        // A skip in setUp() still runs tearDown(), and at that point none of
        // the typed properties below exist. Reading one is a fatal Error
        // that reports as a failure and hides the skip that actually
        // happened -- which is exactly the confusing signal this change set
        // out to remove.
        if (! isset($this->environmentName)) {
            parent::tearDown();

            return;
        }

        $this->server?->stop(2);

        @unlink($this->environmentFile());
        $this->removeTree($this->workspace);
        $this->removeTree($this->base().'/storage/app/e2e_judgehost');

        parent::tearDown();
    }

    /**
     * The judge measures peak RSS with `/usr/bin/time -f` (see
     * config/autojudge.php's rss_time_path), which is GNU time. BSD time --
     * what macOS ships -- rejects -f, so the judged program never runs, the
     * verdict comes back RE instead of AC, and this test fails for a reason
     * that has nothing to do with the judgehost protocol it exists to test.
     *
     * Skipping rather than failing, because two people reading this suite
     * independently reported that failure as a mysterious pre-existing
     * break and spent time on it. A test that cannot run here should say
     * so in one line.
     *
     * CI runs this inside the judge image, which has GNU time, so it does
     * not skip there -- which matters, because that job runs with
     * --fail-on-skipped and a silent skip would be worse than the failure.
     */
    private function skipWithoutGnuTime(): void
    {
        // config() is unavailable here on purpose: this class extends
        // PHPUnit's TestCase, not Laravel's, so there is no container. The
        // default from config/autojudge.php is inlined rather than booted.
        $binary = getenv('AUTOJUDGE_TIME_PATH') ?: '/usr/bin/time';

        $probe = new Process([$binary, '-f', '%M', 'true']);
        $probe->run();

        if (! $probe->isSuccessful()) {
            $this->markTestSkipped(
                "{$binary} does not support -f (GNU time), so the judge cannot measure peak RSS ".
                'and every run comes back RE. Run this suite inside the judge image.'
            );
        }
    }

    private function base(): string
    {
        return dirname(__DIR__, 2);
    }

    private function environmentFile(): string
    {
        return $this->base().'/.env.'.$this->environmentName;
    }

    /**
     * Built from the repository's own .env so the application still has its
     * APP_KEY and anything else it needs, with only what this test depends
     * on replaced.
     */
    private function writeEnvironmentFile(): void
    {
        $lines = [];

        foreach (file($this->base().'/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $key = strtok($line, '=');

            if (! in_array($key, array_keys($this->overrides()), true)) {
                $lines[] = $line;
            }
        }

        foreach ($this->overrides() as $key => $value) {
            $lines[] = $key.'='.$value;
        }

        file_put_contents($this->environmentFile(), implode(PHP_EOL, $lines).PHP_EOL);
    }

    /**
     * @return array<string, string>
     */
    private function overrides(): array
    {
        return [
            'APP_ENV' => $this->environmentName,
            'APP_DEBUG' => 'true',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->serverDatabase,
            // The judgehost routes are throttled, so an unreachable cache
            // makes every one of them a 500 whose body is a connection
            // error -- which is what a partner's agent would see.
            'CACHE_STORE' => 'file',
            'SESSION_DRIVER' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'TELESCOPE_ENABLED' => 'false',
            'PULSE_ENABLED' => 'false',
            'AUTOJUDGE_USE_BWRAP' => 'false',
        ];
    }

    /**
     * Environment for a process that is the SERVER: the seeded database,
     * and a cache the rate limiter can reach. The judgehost routes are
     * throttled, so an unreachable cache makes every one of them a 500.
     *
     * @return array<string, string>
     */
    private function serverEnvironment(): array
    {
        // Both, deliberately. PHPUnit forces DB_DATABASE=:memory: and
        // CACHE_STORE=array into this process's environment, Symfony
        // Process passes the parent environment to children, and Laravel's
        // immutable loader lets a real environment variable beat the file.
        // So the file alone is not enough, and the explicit values are what
        // actually decide it; the file covers anything not listed here.
        return array_merge($this->overrides(), ['APP_ENV' => $this->environmentName]);
    }

    /**
     * And for the AGENT: its own empty database, which is the point.
     *
     * @return array<string, string>
     */
    private function agentEnvironment(string $token): array
    {
        // The agent gets the same configuration with one thing changed: a
        // database of its own, which is empty. If judging needed the
        // contest database this could not pass, and that is the property
        // that lets a judgehost live in someone else's rack.
        return array_merge($this->serverEnvironment(), [
            'DB_DATABASE' => $this->agentDatabase,
            'JUDGEHOST_SERVER' => 'http://127.0.0.1:'.$this->port,
            'JUDGEHOST_TOKEN' => $token,
        ]);
    }

    private function artisan(array $command, array $environment, int $timeout = 120): Process
    {
        $process = new Process(
            array_merge([PHP_BINARY, 'artisan'], $command),
            $this->base(),
            $environment + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
            null,
            $timeout,
        );

        $process->run();

        return $process;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            $this->markTestSkipped("Cannot bind a local port: {$errstr}");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function serverDb(): PDO
    {
        $pdo = new PDO('sqlite:'.$this->serverDatabase);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    public function test_a_judgehost_judges_over_real_http_with_no_database_of_its_own(): void
    {
        $migrate = $this->artisan(['migrate', '--force', '--no-interaction'], $this->serverEnvironment(), 300);
        $this->assertTrue($migrate->isSuccessful(), 'migrate failed: '.$migrate->getErrorOutput());

        $seed = new Process(
            [PHP_BINARY, 'tests/E2E/support/seed_judgehost_scenario.php', $this->workspace],
            $this->base(),
            $this->serverEnvironment() + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
            null,
            120,
        );
        $seed->run();
        $this->assertTrue(
            $seed->isSuccessful() && is_file($this->workspace.'/token'),
            "seed did not produce a credential:\n".$seed->getOutput().$seed->getErrorOutput()
        );

        $token = trim((string) file_get_contents($this->workspace.'/token'));
        $runId = (int) trim((string) file_get_contents($this->workspace.'/run_id'));
        $this->assertNotSame('', $token);

        $this->server = new Process(
            [PHP_BINARY, 'artisan', 'serve', '--host=127.0.0.1', '--port='.$this->port],
            $this->base(),
            $this->serverEnvironment() + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
            null,
            null,
        );
        $this->server->start();

        $this->waitForServer();

        $agent = $this->artisan(['judgehost:work', '--once'], $this->agentEnvironment($token), 300);

        $output = $agent->getOutput().$agent->getErrorOutput();
        $this->assertTrue($agent->isSuccessful(), "agent failed:\n".$output);

        // The verdict travelled the whole way: claimed over HTTP, source
        // and test data fetched over HTTP, judged, reported back, and
        // written by the server -- which is the only process with a
        // database.
        $run = $this->serverDb()
            ->query("select status, answer_id, judgehost_id, claim_token, reported_claim_token from runs where id = {$runId}")
            ->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('judged', $run['status'], "run was not judged:\n".$output);

        $verdict = $this->serverDb()
            ->query('select short_name from answers where id = '.(int) $run['answer_id'])
            ->fetchColumn();
        $this->assertSame('AC', $verdict, "wrong verdict:\n".$output);

        // The lease was released with the verdict, and the fencing token
        // retired into the record of which claim reported it (#123).
        $this->assertNull($run['judgehost_id']);
        $this->assertNull($run['claim_token']);
        $this->assertNotEmpty($run['reported_claim_token']);

        // And the capability probe actually found something. This is the
        // assertion that would have caught the array-shape bug: the agent
        // judged correctly with capabilities empty, so the verdict alone
        // proves nothing about #117.
        $capabilities = $this->serverDb()
            ->query('select extension from judgehost_capabilities')
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame(['sh'], $capabilities, "the agent declared no capabilities:\n".$output);
    }

    private function waitForServer(): void
    {
        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 1);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            if (! $this->server->isRunning()) {
                $this->fail('artisan serve exited: '.$this->server->getErrorOutput());
            }

            usleep(200_000);
        }

        $this->fail('artisan serve did not accept connections within 30s: '.$this->server->getErrorOutput());
    }
}
