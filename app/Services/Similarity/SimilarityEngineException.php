<?php

namespace App\Services\Similarity;

/**
 * Carries a `safeCode` matched against SimilarityCheck::SAFE_ERROR_MESSAGES
 * -- the exception's own getMessage() may contain process stderr, jar
 * paths, or other worker-internal detail and must never reach the client
 * directly (only logged). RunSimilarityCheckJob persists safeCode on the
 * check; the controller renders the mapped human-readable message from it.
 */
class SimilarityEngineException extends \RuntimeException
{
    public function __construct(private readonly string $safeCode, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function safeCode(): string
    {
        return $this->safeCode;
    }
}
