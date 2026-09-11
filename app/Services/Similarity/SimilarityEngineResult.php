<?php

namespace App\Services\Similarity;

final class SimilarityEngineResult
{
    /**
     * @param array<int, array{run_id_a:int, run_id_b:int, score:float}> $pairs Score is a raw 0.0-1.0 fraction, not yet normalized to 0-100.
     * @param int[] $unparseableRunIds Submissions the engine could not analyze at all (distinct from "no similarity found").
     */
    public function __construct(
        public readonly array $pairs,
        public readonly string $engineVersion,
        public readonly array $unparseableRunIds = [],
    ) {}
}
