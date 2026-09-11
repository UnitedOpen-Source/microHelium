<?php

namespace App\Services\Similarity;

/**
 * One team's submission fed into a comparison. `runId` doubles as the
 * submission's directory/id inside the engine (JPlag's own submission ids
 * become exactly these strings), so results can be mapped back to runs
 * without a separate lookup table.
 */
final class SimilarityEngineSubmission
{
    public function __construct(
        public readonly int $runId,
        public readonly string $sourcePath,
        public readonly string $fileExtension,
    ) {}
}
