<?php

namespace App\Services\Similarity;

interface SimilarityEngineInterface
{
    /**
     * @param SimilarityEngineSubmission[] $submissions At least 2 entries.
     *
     * @throws SimilarityEngineException on any failure -- unavailable/corrupt engine, process timeout, or a run producing no usable output.
     */
    public function compare(array $submissions, SimilarityEngineOptions $options): SimilarityEngineResult;
}
