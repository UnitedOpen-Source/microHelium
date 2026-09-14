<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Issue #142 -- a backup that half-worked is worse than one that refused,
 * because the operator walks away believing they have a restore point. Every
 * path in this namespace that cannot produce a complete, readable archive
 * throws this instead of returning something.
 *
 * The message is shown verbatim to the operator (command output, ContestLog),
 * so it is written in Portuguese and says what to do about it.
 */
class BackupFailedException extends RuntimeException {}
