<?php

namespace App\Services\Judgehost;

use App\Models\Language;
use Illuminate\Support\Facades\Process;

/**
 * Issue #117 -- what this machine can actually run, found out rather than
 * declared.
 *
 * A configured list of languages is a list someone has to remember to
 * update, and the day it is wrong a host repeatedly takes work it cannot
 * do and hands it straight back (#125). So this probes: for each language
 * the server knows about, it takes the executable each command actually
 * starts with and asks the shell whether it exists.
 *
 * Deliberately only the binary. Running a real compile of a sample program
 * per language on every restart would be a better test and a much slower
 * start, and the failure it would catch beyond this one -- a toolchain
 * installed but broken -- is already handled by giving the run back.
 */
class MachineCapabilities
{
    /**
     * The extensions this machine can run, out of the ones given.
     *
     * Takes models, plain objects, or the decoded arrays the API returns.
     * That last one is not hypothetical: this shipped reading properties
     * off values that were arrays, so every language was skipped and the
     * agent declared nothing -- silently, because declaring nothing is a
     * valid answer meaning "can judge anything". It degraded safely and did
     * nothing, which is the worst way for a feature to fail.
     *
     * @param  iterable<Language|object|array<string, mixed>>  $languages
     * @return list<string>
     */
    public function detect(iterable $languages): array
    {
        $supported = [];

        foreach ($languages as $language) {
            $extension = (string) ($this->field($language, 'extension') ?? '');

            if ($extension === '' || in_array($extension, $supported, true)) {
                continue;
            }

            $binaries = array_filter([
                $this->executableOf((string) ($this->field($language, 'compile_command') ?? '')),
                $this->executableOf((string) ($this->field($language, 'run_command') ?? '')),
            ]);

            // A language with neither command is not a language this can
            // decide about; leave it to the give-back path rather than
            // claiming an ability that was never checked.
            if ($binaries === []) {
                continue;
            }

            if (collect($binaries)->every(fn (string $binary) => $this->exists($binary))) {
                $supported[] = $extension;
            }
        }

        return $supported;
    }

    /**
     * One field, however the caller happens to be carrying it.
     */
    private function field(mixed $language, string $key): mixed
    {
        if (is_array($language)) {
            return $language[$key] ?? null;
        }

        return is_object($language) ? ($language->{$key} ?? null) : null;
    }

    public function cpuCount(): ?int
    {
        $count = (int) trim((string) @shell_exec('nproc 2>/dev/null'));

        return $count > 0 ? $count : null;
    }

    public function memoryMb(): ?int
    {
        $kb = (int) trim((string) @shell_exec("awk '/MemTotal/ {print \$2}' /proc/meminfo 2>/dev/null"));

        return $kb > 0 ? intdiv($kb, 1024) : null;
    }

    /**
     * The program a command line actually starts, skipping the environment
     * assignments a command template may begin with.
     */
    private function executableOf(string $command): ?string
    {
        foreach (preg_split('/\s+/', trim($command)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }

            // VAR=value prefixes, and the placeholders the templates use
            // for paths the judge substitutes later.
            if (str_contains($token, '=') || str_starts_with($token, '{')) {
                continue;
            }

            return $token;
        }

        return null;
    }

    private function exists(string $binary): bool
    {
        // An absolute path is checked directly; anything else is resolved
        // the way the judge itself would resolve it, through PATH.
        if (str_starts_with($binary, '/')) {
            return is_file($binary) && is_executable($binary);
        }

        return Process::run(['sh', '-c', 'command -v '.escapeshellarg($binary)])->successful();
    }
}
