<?php

namespace App\Services\EventImport;

/**
 * Issue #147 -- the result of reading an event file: the contest and its
 * sites, languages and problems, plus everything that could not be
 * understood, each with the path where it sits in the file.
 *
 * Like ParsedTeamFile (#141) this reports *all* problems at once rather
 * than aborting on the first one. An organiser typing a 40-site file wants
 * one list of 6 mistakes, not 6 edit-and-rerun cycles the day before the
 * contest.
 *
 * Errors refuse the import; warnings do not. The split exists because some
 * things are certainly wrong (a site with no name) and some are only
 * probably wrong (a freeze longer than the contest itself) -- and an
 * importer that refuses a legal-but-odd configuration is an importer people
 * stop using.
 */
final class ParsedEvent
{
    /**
     * @param  array<string, mixed>  $contest  column => value for the contests row
     * @param  list<EventEntry>  $sites
     * @param  list<EventEntry>  $languages
     * @param  list<EventEntry>  $problems
     * @param  list<array{location: string, message: string}>  $errors
     * @param  list<array{location: string, message: string}>  $warnings
     */
    public function __construct(
        public readonly array $contest,
        public readonly array $sites,
        public readonly array $languages,
        public readonly array $problems,
        public readonly array $errors,
        public readonly array $warnings,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * An empty result, for the cases where the file could not be decoded at
     * all and there is nothing to say beyond the syntax error itself.
     *
     * @param  list<array{location: string, message: string}>  $errors
     */
    public static function failed(array $errors): self
    {
        return new self([], [], [], [], $errors, []);
    }
}
