<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Issue #61: config/queue.php read the non-standard QUEUE_DRIVER env key,
 * while .env (and every other Laravel app) sets QUEUE_CONNECTION -- so the
 * app silently ran the entire auto-judge queue on the 'sync' driver
 * (executing jobs immediately in-request) instead of the intended Redis.
 *
 * PHPUnit's own environment already pins QUEUE_CONNECTION=sync (see
 * phpunit.xml), so asserting config('queue.default') here wouldn't actually
 * distinguish "reads the right env var" from "happens to match the
 * fallback default anyway" -- this re-evaluates the raw config file with a
 * controlled environment instead, independent of Laravel's already-booted
 * config cache.
 */
class QueueConfigTest extends TestCase
{
    public function test_config_file_reads_queue_connection_not_queue_driver()
    {
        $this->setEnv('QUEUE_CONNECTION', 'redis');
        $this->setEnv('QUEUE_DRIVER', 'beanstalkd');

        $config = require base_path('config/queue.php');

        $this->setEnv('QUEUE_CONNECTION', null);
        $this->setEnv('QUEUE_DRIVER', null);

        $this->assertSame('redis', $config['default']);
    }

    /**
     * env()'s lookup order (Illuminate\Support\Env / vlucas/phpdotenv)
     * checks $_ENV/$_SERVER before getenv(), so a plain putenv() alone
     * isn't reliably picked up once the app has already booted.
     */
    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    public function test_redis_retry_after_exceeds_judge_run_jobs_timeout()
    {
        $config = require base_path('config/queue.php');

        $this->assertGreaterThan(
            (new \App\Jobs\JudgeRunJob(new \App\Models\Run()))->timeout,
            $config['connections']['redis']['retry_after'],
            'retry_after must exceed JudgeRunJob::$timeout, or Redis can hand a still-running job to another worker'
        );
    }
}
