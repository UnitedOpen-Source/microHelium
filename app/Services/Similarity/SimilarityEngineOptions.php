<?php

namespace App\Services\Similarity;

final class SimilarityEngineOptions
{
    public function __construct(
        public readonly string $jplagLanguage,
        /** Fraction 0.0-1.0 -- the admin-facing 0-100 threshold divided by 100 exactly once, by the caller. */
        public readonly float $thresholdFraction,
        public readonly int $maxPairs,
        public readonly int $timeoutSeconds,
        public readonly int $memoryLimitMb,
        public readonly ?int $minimumTokenMatch = null,
        public readonly ?string $baseCodePath = null,
    ) {}
}
