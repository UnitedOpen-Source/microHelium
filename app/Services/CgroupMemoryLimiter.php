<?php

namespace App\Services;

/**
 * Issue #86 -- a real resident-memory cap for one judged run, via cgroup v2.
 *
 * The peak-RSS measurement that landed in #108 produces the MLE verdict but
 * bounds nothing: a program that allocates 4 GB is judged MLE *after* it has
 * taken 4 GB off the machine. `ulimit -v` is a barrier, but it caps ADDRESS
 * SPACE, and the JVM and .NET reserve gigabytes of it before running any
 * submitted code -- which is why config/autojudge.php gives them no barrier
 * at all. A cgroup `memory.max` caps resident memory, so it is the first
 * limit that fits every language: measured on this image, a trivial Java or
 * Kotlin program peaks around 27 MB under a 256 MB cap, and an allocating
 * one is killed at exactly the cap.
 *
 * The subtree this writes into is delegated to uid 1000 by
 * docker/judge/entrypoint.sh, which needs a privileged container. When it
 * is absent -- an unprivileged container, a cgroup v1 host, a developer's
 * laptop -- every method here degrades to doing nothing and the peak-RSS
 * path decides the verdict on its own. Judging never stops for want of a
 * cgroup.
 */
class CgroupMemoryLimiter
{
    protected string $root;

    public function __construct(?string $root = null)
    {
        $this->root = (string) ($root ?? config('autojudge.cgroup_root', '/sys/fs/cgroup/judge'));
    }

    /**
     * Is there a delegated subtree this process can actually create
     * per-run cgroups in?
     */
    public function isAvailable(): bool
    {
        return $this->root !== '' && is_dir($this->root) && is_writable($this->root);
    }

    /**
     * $command, wrapped so it runs inside a fresh cgroup capped at
     * $memoryLimitMb -- or returned untouched when no cgroup could be made.
     */
    public function confine(string $command, string $name, int $memoryLimitMb): string
    {
        $path = $this->pathFor($name);

        if ($path === null || ! $this->create($path, $memoryLimitMb)) {
            return $command;
        }

        // The shell joins the cgroup and only THEN execs, so bwrap is
        // already a member before it starts and every process it spawns
        // inherits the membership. Nothing gets to allocate outside the
        // cap, not even during the runtime's startup -- which is the whole
        // difference between this and measuring afterwards.
        return 'sh -c '.escapeshellarg(
            'echo $$ > '.escapeshellarg($path.'/cgroup.procs').'; exec '.$command
        );
    }

    /**
     * What the run just finished used, then removes its cgroup. Null when
     * there was no cgroup to read.
     *
     * @return array{peak_bytes: ?int, oom_kills: int}|null
     */
    public function release(string $name): ?array
    {
        $path = $this->pathFor($name);

        if ($path === null || ! is_dir($path)) {
            return null;
        }

        // memory.peak is a 5.19 kernel and up; older ones still report
        // oom_kill, which is the conclusive half of the verdict anyway.
        $usage = [
            'peak_bytes' => $this->readInt($path.'/memory.peak'),
            'oom_kills' => $this->readEvent($path.'/memory.events', 'oom_kill') ?? 0,
        ];

        $this->remove($path);

        return $usage;
    }

    /**
     * Did this run hit its cap?
     *
     * `oom_kill` is conclusive on its own: the kernel had to kill something
     * to hold the line. The peak is not, and needs the second argument --
     * `memory.max` does NOT guarantee an OOM kill (an allocation the kernel
     * refuses can simply return NULL, indistinguishable from any other
     * failure), so the peak has to be consulted too; but memory.peak counts
     * page cache as well as anonymous memory, and a run that merely read a
     * large input can touch the ceiling without ever being denied a byte,
     * the kernel just reclaiming cache underneath it. So the peak only gets
     * to decide for a run that actually failed, where the open question is
     * which failure it was.
     *
     * @param  array{peak_bytes: ?int, oom_kills: int}|null  $usage
     */
    public function exceeded(?array $usage, int $memoryLimitMb, bool $succeeded): bool
    {
        if ($usage === null) {
            return false;
        }

        if ($usage['oom_kills'] > 0) {
            return true;
        }

        if ($succeeded || $usage['peak_bytes'] === null) {
            return false;
        }

        // Not `>`: memory.peak is clamped by memory.max, so a run that hit
        // the ceiling reports the ceiling exactly and never more.
        return $usage['peak_bytes'] >= $this->limitBytes($memoryLimitMb);
    }

    /**
     * The cgroup directory for $name, or null when the name is unusable or
     * the subtree is not there.
     */
    protected function pathFor(string $name): ?string
    {
        if (! $this->isAvailable()) {
            return null;
        }

        // A cgroup name reaches a shell command and a filesystem path, and
        // is built from a run id -- but the allowlist is cheaper than
        // trusting that it always will be.
        if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $name) !== 1) {
            return null;
        }

        return $this->root.'/'.$name;
    }

    /**
     * Creates the cgroup and writes its limits. False if any of it fails,
     * having left nothing behind.
     */
    protected function create(string $path, int $memoryLimitMb): bool
    {
        // A previous run that died without releasing would otherwise block
        // its successor for as long as the name repeats.
        if (is_dir($path)) {
            $this->remove($path);
        }

        if (! @mkdir($path, 0755)) {
            return false;
        }

        if (@file_put_contents($path.'/memory.max', (string) $this->limitBytes($memoryLimitMb)) === false) {
            $this->remove($path);

            return false;
        }

        // Without this a capped program would just page out to swap and
        // keep going, and the cap would bound nothing. Absent when the
        // kernel has swap accounting off, which is not a failure.
        @file_put_contents($path.'/memory.swap.max', '0');

        return true;
    }

    protected function remove(string $path): void
    {
        // A run that left a process behind holds the cgroup and rmdir(2)
        // fails with EBUSY. cgroup.kill reaps whatever is still in there;
        // it is asynchronous, hence the retries below.
        @file_put_contents($path.'/cgroup.kill', '1');

        // Retrying only makes sense for a real cgroup, where rmdir(2)
        // removes a populated directory and EBUSY means "not yet". On
        // anything else -- a stubbed root in a test, a path that is not
        // what it was -- waiting changes nothing.
        $attempts = is_file($path.'/cgroup.procs') ? 20 : 1;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if (@rmdir($path)) {
                return;
            }

            usleep(10000);
        }
    }

    protected function limitBytes(int $memoryLimitMb): int
    {
        return max(1, $memoryLimitMb) * 1024 * 1024;
    }

    protected function readInt(string $path): ?int
    {
        $contents = @file_get_contents($path);

        if ($contents === false || preg_match('/^\d+/', trim($contents), $matches) !== 1) {
            return null;
        }

        return (int) $matches[0];
    }

    /**
     * One counter out of the `key value` table in memory.events.
     */
    protected function readEvent(string $path, string $key): ?int
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        if (preg_match('/^'.preg_quote($key, '/').'\s+(\d+)$/m', $contents, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
