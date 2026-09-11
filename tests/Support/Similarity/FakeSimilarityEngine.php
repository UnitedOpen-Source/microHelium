<?php

namespace Tests\Support\Similarity;

use App\Services\Similarity\SimilarityEngineException;
use App\Services\Similarity\SimilarityEngineInterface;
use App\Services\Similarity\SimilarityEngineOptions;
use App\Services\Similarity\SimilarityEngineResult;
use App\Services\Similarity\SimilarityEngineSubmission;

/**
 * Deterministic, network-free stand-in for JplagSimilarityEngine used by
 * the test suite (bound over the real interface via
 * $this->app->instance(SimilarityEngineInterface::class, ...)). Never
 * downloads or executes the real JPlag jar.
 *
 * Default behavior: submissions whose source file contents are byte-identical
 * score 1.0 (100%), everything else scores 0.0 -- a small, controlled
 * fixture is enough to exercise "known pair" calculation and scale
 * (acceptance criterion: "Fixture controlada com um par conhecido verifica
 * cálculo e escala").
 */
class FakeSimilarityEngine implements SimilarityEngineInterface
{
    /** @var (\Closure(SimilarityEngineSubmission[], SimilarityEngineOptions): SimilarityEngineResult)|null */
    public $behavior = null;

    public ?\Throwable $throws = null;

    public function compare(array $submissions, SimilarityEngineOptions $options): SimilarityEngineResult
    {
        if ($this->throws) {
            throw $this->throws;
        }

        if ($this->behavior) {
            return ($this->behavior)($submissions, $options);
        }

        $pairs = [];
        foreach ($submissions as $i => $a) {
            foreach ($submissions as $j => $b) {
                if ($j <= $i) {
                    continue;
                }
                $score = file_get_contents($a->sourcePath) === file_get_contents($b->sourcePath) ? 1.0 : 0.0;
                $pairs[] = ['run_id_a' => $a->runId, 'run_id_b' => $b->runId, 'score' => $score];
            }
        }

        return new SimilarityEngineResult($pairs, 'fake-1.0.0');
    }

    public static function timingOut(): self
    {
        $engine = new self();
        $engine->throws = new SimilarityEngineException('engine_timeout', 'fake timeout');
        return $engine;
    }

    public static function failing(): self
    {
        $engine = new self();
        $engine->throws = new SimilarityEngineException('engine_failed', 'fake failure');
        return $engine;
    }
}
