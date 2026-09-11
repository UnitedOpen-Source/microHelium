<?php

namespace App\Services\Similarity;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Shells out to the real JPlag jar (see config/similarity.php) via
 * Laravel's Process facade, entirely in an isolated temp directory,
 * with structured argv (never a shell string built from names/ids), and
 * cleans that directory up afterwards regardless of outcome.
 *
 * "sem rede": JPlag's RUN mode performs no network I/O on its own -- there
 * is no flag here that would opt into its (separate, explicitly-invoked)
 * hosted report upload feature. Enforcing that at the OS/container level
 * (e.g. an egress-restricted queue worker) is out of scope for this PHP
 * class and is called out as an operational follow-up in
 * docs/specs/42-similarity.md.
 */
class JplagSimilarityEngine implements SimilarityEngineInterface
{
    public function compare(array $submissions, SimilarityEngineOptions $options): SimilarityEngineResult
    {
        $this->assertJarTrusted();

        $workDir = storage_path('app/similarity-tmp/' . (string) Str::uuid());
        $submissionsDir = "{$workDir}/subs";
        File::ensureDirectoryExists($submissionsDir);

        try {
            foreach ($submissions as $submission) {
                $submissionDir = "{$submissionsDir}/{$submission->runId}";
                File::ensureDirectoryExists($submissionDir);
                File::copy($submission->sourcePath, "{$submissionDir}/submission.{$submission->fileExtension}");
            }

            $baseCodeArgs = [];
            if ($options->baseCodePath && File::isDirectory($options->baseCodePath)) {
                File::copyDirectory($options->baseCodePath, "{$submissionsDir}/__base__");
                $baseCodeArgs = ['-bc', '__base__'];
            }

            $args = array_merge(
                [
                    config('similarity.jplag.java_binary', 'java'),
                    '-Xmx' . max(64, $options->memoryLimitMb) . 'm',
                    '-jar', config('similarity.jplag.jar_path'),
                    '-l', $options->jplagLanguage,
                    '-r', 'result',
                    '-m', number_format($options->thresholdFraction, 4, '.', ''),
                    '-n', (string) $options->maxPairs,
                ],
                $options->minimumTokenMatch ? ['-t', (string) $options->minimumTokenMatch] : [],
                $baseCodeArgs,
                ['subs']
            );

            $result = Process::path($workDir)
                ->timeout($options->timeoutSeconds)
                ->run($args);

            if (!$result->successful()) {
                Log::warning('Similarity engine: JPlag exited with a non-zero status', [
                    'exit_code' => $result->exitCode(),
                    'stderr' => Str::limit($result->errorOutput(), 2000),
                ]);
                throw new SimilarityEngineException('engine_failed', 'JPlag exited with status ' . $result->exitCode());
            }

            return $this->parseResult("{$workDir}/result.jplag");
        } catch (ProcessTimedOutException $e) {
            throw new SimilarityEngineException('engine_timeout', 'JPlag timed out', $e);
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    private function assertJarTrusted(): void
    {
        $jarPath = config('similarity.jplag.jar_path');
        $expectedSha256 = config('similarity.jplag.jar_sha256');

        if (!$jarPath || !is_file($jarPath)) {
            throw new SimilarityEngineException('engine_unavailable', 'JPlag jar not found at the configured path');
        }

        $actualSha256 = hash_file('sha256', $jarPath);
        if (!$expectedSha256 || !hash_equals($expectedSha256, (string) $actualSha256)) {
            Log::error('Similarity engine: JPlag jar checksum mismatch, refusing to execute it');
            throw new SimilarityEngineException('engine_unavailable', 'JPlag jar checksum mismatch');
        }
    }

    /**
     * `-r result` writing exactly `{cwd}/result.jplag`, and that file's
     * internal `runInformation.json`/`topComparisons.json` shape (fraction
     * similarities under `similarities.AVG`, run ids as the literal
     * submission directory names) were NOT guessed from documentation --
     * they were confirmed by actually running the pinned jplag-6.2.0
     * jar-with-dependencies.jar locally against a small 3-submission Java
     * fixture and inspecting the resulting archive byte for byte (see the
     * "JPlag e JDK" / "Saída do JPlag" sections of
     * docs/specs/42-similarity.md for how and when). If a future version
     * bump changes any of this, re-run that same manual check before
     * trusting the parser again -- the test suite can't catch a real-jar
     * format drift since it only exercises FakeSimilarityEngine.
     */
    private function parseResult(string $resultJplagPath): SimilarityEngineResult
    {
        if (!is_file($resultJplagPath)) {
            throw new SimilarityEngineException('engine_failed', 'JPlag produced no result file');
        }

        $zip = new \ZipArchive();
        if ($zip->open($resultJplagPath) !== true) {
            throw new SimilarityEngineException('engine_failed', 'JPlag result file is not a readable archive');
        }

        try {
            $runInformation = $this->readJsonEntry($zip, 'runInformation.json');
            $topComparisons = $this->readJsonEntry($zip, 'topComparisons.json') ?? [];

            $version = $runInformation['version'] ?? null;
            if (!is_array($version) || !isset($version['major'], $version['minor'], $version['patch'])) {
                throw new SimilarityEngineException('engine_failed', 'JPlag result is missing version metadata');
            }
            $engineVersion = "jplag-{$version['major']}.{$version['minor']}.{$version['patch']}";

            $pairs = [];
            foreach ($topComparisons as $comparison) {
                if (!isset($comparison['firstSubmission'], $comparison['secondSubmission'], $comparison['similarities']['AVG'])) {
                    continue;
                }
                $pairs[] = [
                    'run_id_a' => (int) $comparison['firstSubmission'],
                    'run_id_b' => (int) $comparison['secondSubmission'],
                    // AVG is already a 0.0-1.0 fraction; normalization to
                    // 0-100 happens exactly once, in RunSimilarityCheckJob.
                    'score' => (float) $comparison['similarities']['AVG'],
                ];
            }

            $unparseable = array_map('intval', $runInformation['failedSubmissions'] ?? []);

            return new SimilarityEngineResult($pairs, $engineVersion, $unparseable);
        } finally {
            $zip->close();
        }
    }

    private function readJsonEntry(\ZipArchive $zip, string $name): ?array
    {
        $contents = $zip->getFromName($name);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : null;
    }
}
