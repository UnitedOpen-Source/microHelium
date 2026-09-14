<?php

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * A managed account could not be created because `users.username` is taken.
 *
 * Exists so that ManagedAccountProvisioner can stay framework-shaped rather
 * than HTTP-shaped: the web controller turns this into a 422
 * ValidationException, while the bulk importer (issue #141) turns it into a
 * single reported row so the other 59 teams in the file still get created.
 * Throwing ValidationException from the provisioner would have forced the
 * console command to catch an HTTP concern it has no business knowing about.
 */
class UsernameTakenException extends RuntimeException
{
    public function __construct(public readonly string $username, ?Throwable $previous = null)
    {
        parent::__construct("Nome de usuario ja esta em uso: {$username}.", 0, $previous);
    }
}
