<?php

namespace App\Jobs;

use App\Models\Run;
use App\Models\SimilarityCheck;
use App\Models\SimilarityPair;
use App\Services\Similarity\SimilarityEngineException;
use App\Services\Similarity\SimilarityEngineInterface;
use App\Services\Similarity\SimilarityEngineOptions;
use App\Services\Similarity\SimilarityEngineSubmission;
use App\Services\Similarity\SimilarityLanguageMap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * queued -> running -> completed|failed (docs/specs/42-similarity.md).
 * $tries=1 deliberately: an automatic retry would silently re-run JPlag
 * against a snapshot that may no longer reflect current DB rows, and the
 * spec requires a clean failed state rather than a hidden second attempt --
 * an admin can always request a brand-new check instead.
 *
 * Mirrors JudgeRunJob's failed()-guards-against-stuck-running pattern: if
 * the queue worker is killed mid-run (OOM, deploy) without handle()'s own
 * catch block running, failed() still flips the check out of "running" so
 * it never stays stuck indefinitely.
 */
class RunSimilarityCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout;

    public function __construct(public SimilarityCheck $check)
    {
        $this->timeout = (int) config('similarity.timeout_seconds', 240) + 60;
    }

    public function handle(SimilarityEngineInterface $engine): void
    {
        $this->check->refresh();

        // Guards against a duplicate dispatch (e.g. a queue redelivery)
        // re-running an already-settled check.
        if ($this->check->status !== 'queued') {
            return;
        }

        $this->check->update(['status' => 'running']);

        $runIds = $this->check->snapshot['run_ids'] ?? [];
        $runs = Run::whereIn('id', $runIds)->get()->keyBy('id');

        if ($runs->count() < 2) {
            $this->failSafely('insufficient_sources');
            return;
        }

        $language = $this->check->language;
        $jplagLanguage = SimilarityLanguageMap::jplagLanguageFor($language);
        if (!$jplagLanguage) {
            // Defense in depth: the controller already rejects unsupported
            // languages at request time, so this should be unreachable
            // unless the language was changed after the check was queued.
            $this->failSafely('engine_failed');
            return;
        }

        $submissions = [];
        foreach ($runIds as $runId) {
            $run = $runs->get($runId);
            if (!$run) {
                continue;
            }
            $submissions[] = new SimilarityEngineSubmission(
                runId: $run->id,
                sourcePath: $run->getSourcePath(),
                fileExtension: $language->getFileExtension(),
            );
        }

        $options = new SimilarityEngineOptions(
            jplagLanguage: $jplagLanguage,
            thresholdFraction: $this->check->threshold / 100,
            maxPairs: (int) config('similarity.max_pairs_per_report', 300),
            timeoutSeconds: (int) config('similarity.timeout_seconds', 240),
            memoryLimitMb: (int) config('similarity.memory_limit_mb', 1024),
            minimumTokenMatch: config('similarity.jplag.minimum_token_match') !== null
                ? (int) config('similarity.jplag.minimum_token_match')
                : null,
            baseCodePath: config('similarity.base_code_path'),
        );

        try {
            $result = $engine->compare($submissions, $options);
        } catch (SimilarityEngineException $e) {
            Log::warning('Similarity check failed', ['check_id' => $this->check->id, 'safe_code' => $e->safeCode(), 'detail' => $e->getMessage()]);
            $this->failSafely($e->safeCode());
            return;
        } catch (\Throwable $e) {
            Log::error('Similarity check failed unexpectedly', ['check_id' => $this->check->id, 'detail' => $e->getMessage()]);
            $this->failSafely('engine_failed');
            return;
        }

        DB::transaction(function () use ($result) {
            foreach ($result->pairs as $pair) {
                // Normalize JPlag's 0.0-1.0 fraction to the 0-100 scale
                // exactly once, here.
                $scorePercent = round($pair['score'] * 100, 2);
                if ($scorePercent < $this->check->threshold) {
                    continue;
                }

                $runIdA = min($pair['run_id_a'], $pair['run_id_b']);
                $runIdB = max($pair['run_id_a'], $pair['run_id_b']);

                SimilarityPair::updateOrCreate(
                    [
                        'similarity_check_id' => $this->check->id,
                        'run_id_a' => $runIdA,
                        'run_id_b' => $runIdB,
                    ],
                    ['similarity_score' => $scorePercent]
                );
            }

            $this->check->update([
                'status' => 'completed',
                'engine_version' => $result->engineVersion,
            ]);
        });
    }

    /**
     * Reached only when something outside handle()'s own try/catch killed
     * the job -- a real job-level timeout (pcntl alarm -> Laravel's
     * TimeoutExceededException), or an unrelated fatal (OOM kill, lost DB
     * connection). Only the former is actually a timeout; mislabeling the
     * latter as "engine_timeout" would tell the admin something false
     * about what happened, so anything else gets a generic
     * "worker_interrupted" code instead.
     */
    public function failed(?\Throwable $exception = null): void
    {
        $this->check->refresh();
        if (in_array($this->check->status, ['completed', 'failed'], true)) {
            return;
        }

        $safeCode = $exception instanceof \Illuminate\Queue\TimeoutExceededException
            ? 'engine_timeout'
            : 'worker_interrupted';

        $this->check->update(['status' => 'failed', 'safe_error_code' => $safeCode]);
    }

    private function failSafely(string $safeCode): void
    {
        $this->check->update(['status' => 'failed', 'safe_error_code' => $safeCode]);
    }
}
