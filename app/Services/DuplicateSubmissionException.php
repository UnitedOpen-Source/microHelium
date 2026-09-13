<?php

namespace App\Services;

use RuntimeException;

/**
 * An identical source was already submitted for this contest/problem/user
 * and the caller asked for duplicates to be refused. Carries the existing
 * run's number so the caller can name it in its own error message.
 */
class DuplicateSubmissionException extends RuntimeException
{
    public function __construct(public readonly string $existingRunNumber)
    {
        parent::__construct("Submissao identica ja enviada (run #{$existingRunNumber}).");
    }
}
