<?php

namespace App\Services\TeamImport;

/**
 * Issue #141 -- one team successfully read out of an ICPC/BOCA import file,
 * already normalised into the shape ManagedAccountProvisioner wants.
 *
 * `line` is the 1-based physical line in the file (the block's first line
 * for the BOCA users.txt format). It is carried all the way to the console
 * table so an organiser fixing a 60-row file is told *which* row to look
 * at, rather than being handed a count of failures.
 */
final class ParsedTeamRow
{
    public function __construct(
        public readonly int $line,
        /** ICPC team id from the file. Null only for users.txt blocks that omit usericpcid. */
        public readonly ?string $icpcId,
        public readonly string $username,
        public readonly string $fullname,
        /** Institution long name (BOCA's userdesc), stored in users.description. */
        public readonly ?string $description,
        /** Site number as written in the file; null when the format omits it. */
        public readonly ?int $siteNumber,
        /**
         * The file carried a plaintext password for this row and we threw
         * it away. Surfaced so the command can warn instead of silently
         * ignoring something the organiser wrote on purpose.
         */
        public readonly bool $passwordDiscarded = false,
    ) {}

    /**
     * The value that decides "is this the same team as one already in the
     * contest". See TeamImportCommand for why it is the ICPC id when the
     * file has one and the username otherwise.
     */
    public function identity(): string
    {
        return $this->icpcId !== null ? 'icpc:'.$this->icpcId : 'user:'.mb_strtolower($this->username);
    }
}
