<?php

namespace Tests\Concerns;

/**
 * Issue #49 -- for a test that really compiles and runs submitted code.
 *
 * AutoJudgeService refuses to execute submissions unconfined, so the only
 * configuration judging is ever allowed to happen in is inside the bwrap
 * sandbox -- and that is therefore the configuration these tests have to
 * exercise. phpunit.xml defaults autojudge.use_bwrap off for the suite at
 * large so the other ~750 tests run on a machine without bubblewrap; this
 * trait turns it back on for the ones that judge, and skips them where no
 * usable sandbox exists rather than reporting an environment gap as a
 * product failure.
 */
trait RequiresJudgeSandbox
{
    /**
     * Call at the very top of setUp(), BEFORE parent::setUp(): skipping once
     * the application has booted leaves Laravel's error and exception
     * handlers installed (tearDown never runs), which PHPUnit reports as
     * risky -- and phpunit.xml sets failOnRisky.
     */
    protected function skipUnlessJudgeSandboxAvailable(): void
    {
        $bwrap = getenv('AUTOJUDGE_BWRAP_PATH') ?: '/usr/bin/bwrap';

        if (!is_executable($bwrap)) {
            $this->markTestSkipped("bubblewrap is not installed at {$bwrap}");
        }

        // Installed is not the same as usable: creating a user namespace can
        // still be denied (an unprivileged container, a hardened kernel).
        // Prove it works on an empty sandbox before letting the suite draw
        // conclusions from what happens inside one.
        $binds = '';
        foreach (['/usr', '/bin', '/sbin', '/lib', '/lib64'] as $path) {
            if (file_exists($path)) {
                $binds .= '--ro-bind ' . escapeshellarg($path) . ' ' . escapeshellarg($path) . ' ';
            }
        }

        exec(
            escapeshellarg($bwrap) . ' --unshare-all --die-with-parent ' . $binds
            . '--proc /proc --dev /dev /bin/sh -c "exit 0" 2>/dev/null',
            $ignored,
            $exitCode
        );

        if ($exitCode !== 0) {
            $this->markTestSkipped('bubblewrap cannot create a sandbox here (user namespaces unavailable?)');
        }
    }

    /**
     * Call after parent::setUp(), once the config repository exists.
     */
    protected function enableJudgeSandbox(): void
    {
        config(['autojudge.use_bwrap' => true]);
    }
}
