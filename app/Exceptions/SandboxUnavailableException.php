<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Issue #49 -- the judge sandbox is missing, unusable, or failed to start.
 *
 * Thrown instead of letting submitted code run unconfined on the host, and
 * deliberately distinct from a compilation/runtime error produced *by* the
 * submission: docs/specs/49-judge-isolation.md requires that "histórico
 * distingue indisponibilidade técnica de resposta incorreta [...] sem
 * penalizar equipe por erro operacional". AutoJudgeService::judge() lets it
 * bubble to handleJudgingError(), which records the run as CS rather than
 * CE/RE.
 *
 * Extends RuntimeException so existing callers catching \RuntimeException
 * (and \Exception) keep working unchanged.
 */
class SandboxUnavailableException extends RuntimeException {}
