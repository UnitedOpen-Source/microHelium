<?php

namespace App\Services\Judgehost;

use RuntimeException;
use Throwable;

/**
 * Issue #125 -- this machine cannot judge this run, and why.
 *
 * The reason is the point. A judgehost handing a run back is ordinary, but
 * a run every host hands back is not, and the organisers can only tell the
 * two apart if the machine says which of them is happening. Free text does
 * not aggregate, so the reason is one of a closed set the server knows
 * (WorkController::GIVE_BACK_REASONS) and the message is detail on top.
 */
class UnjudgeableRun extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
