<?php

namespace App\Services\TeamImport;

/**
 * Issue #141 -- the result of reading a team file: the rows that could be
 * understood, plus every row that could not, each with its line number.
 *
 * Both halves are returned together on purpose. Aborting on the first bad
 * line (BOCA's `break`) means an organiser fixes one typo, re-runs, and
 * finds the next one; reporting everything at once means one pass over the
 * file. A line number of 0 marks a problem with the file as a whole rather
 * than with a particular row.
 */
final class ParsedTeamFile
{
    /**
     * @param  list<ParsedTeamRow>  $rows
     * @param  list<array{line: int, message: string}>  $errors
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
