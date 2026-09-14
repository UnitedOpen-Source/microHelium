<?php

namespace App\Services\TeamImport;

/**
 * Issue #141 -- reads the three team-file formats BOCA documents in
 * doc/import-user.txt and turns them into ParsedTeamRow objects.
 *
 * Field semantics were taken from BOCA's own importer (src/admin/user.php,
 * the `.tsv` / `.tab` / users.txt branches) rather than guessed from the
 * sample files, because several columns are *not* what their position
 * suggests -- see the per-format notes below. No BOCA code is reused; the
 * language and the schema are both different.
 *
 * The parser never touches the database and never throws for bad input: a
 * row it cannot read becomes an entry in ParsedTeamFile::$errors with its
 * line number, so one corrupt line in a 60-team file does not cost the
 * other 59 (the failure mode the issue is explicitly about).
 */
class TeamFileParser
{
    /** ICPC "PC2 team" export: 9 TAB-separated columns, no header line. */
    public const FORMAT_PC2_TAB = 'pc2tab';

    /** ICPC "Teams" export: `File_Version<TAB>1` header, then 7 TAB-separated columns. */
    public const FORMAT_TEAMS_TSV = 'tsv';

    /** BOCA's own users.txt: `[user]` marker, then key=value blocks. */
    public const FORMAT_BOCA_USERS = 'boca';

    public const FORMATS = [self::FORMAT_PC2_TAB, self::FORMAT_TEAMS_TSV, self::FORMAT_BOCA_USERS];

    /**
     * users.username is validated at max:80 by the managed-accounts screen;
     * the importer holds itself to the same limit so a login created by
     * either path is equally editable afterwards.
     */
    private const MAX_USERNAME = 80;

    /** users.fullname and users.icpc_id are varchar(255) / varchar(50). */
    private const MAX_FULLNAME = 255;

    private const MAX_ICPC_ID = 50;

    public function parse(string $contents, string $format): ParsedTeamFile
    {
        $lines = explode("\n", $this->normalise($contents));

        return match ($format) {
            self::FORMAT_PC2_TAB => $this->parseTabular($lines, 9),
            self::FORMAT_TEAMS_TSV => $this->parseTabular($lines, 7),
            self::FORMAT_BOCA_USERS => $this->parseBocaUsers($lines),
            default => new ParsedTeamFile([], [['line' => 0, 'message' => "Formato desconhecido: {$format}."]]),
        };
    }

    /**
     * Content first, filename second. BOCA decides purely on the extension
     * (`.tsv` / `.tab` / anything else), which misfires the moment someone
     * saves the ICPC download as `teams.txt` or `equipes.tab` -- and
     * misfiring here means every row is reported as having the wrong
     * column count, which reads like a corrupt file rather than a wrong
     * guess. The header markers are unambiguous, so use them when present.
     */
    public function detectFormat(string $contents, string $filename = ''): ?string
    {
        $normalised = $this->normalise($contents);

        if (preg_match('/^File_Version\t/m', $normalised) === 1) {
            return self::FORMAT_TEAMS_TSV;
        }

        if (preg_match('/^\s*\[user\]\s*$/m', $normalised) === 1) {
            return self::FORMAT_BOCA_USERS;
        }

        // No marker: fall back to counting columns on the first non-empty
        // line. 9 and 7 are the two ICPC layouts; a users.txt without its
        // [user] marker is not something BOCA accepts either.
        foreach (explode("\n", $normalised) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $columns = count(explode("\t", $line));

            if ($columns === 9) {
                return self::FORMAT_PC2_TAB;
            }

            if ($columns === 7) {
                return self::FORMAT_TEAMS_TSV;
            }

            break;
        }

        $extension = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'tab' => self::FORMAT_PC2_TAB,
            'tsv' => self::FORMAT_TEAMS_TSV,
            default => null,
        };
    }

    /**
     * Both ICPC exports are TAB-separated with a fixed column count and
     * carry the same five fields in different positions, so they share one
     * routine. The positions, from BOCA's importer:
     *
     *   .tab (9 cols): 0 team id, 1 site, 2 division, 3 team name,
     *                  4 institution, 5 institution short, 6 url,
     *                  7 country, 8 active flag
     *   .tsv (7 cols): 0 unused ("null"), 1 team id, 2 site,
     *                  3 team name, 4 institution, 5 institution short,
     *                  6 country
     *
     * Columns 6-8 of the .tab file are read by nobody, BOCA included --
     * note in particular that BOCA imports a row whose column 8 says "N",
     * and this importer deliberately matches that rather than inventing a
     * filter the organiser did not ask for. What "N" means in an ICPC
     * export is not documented anywhere we can check, and silently dropping
     * teams is the worse failure.
     *
     * @param  list<string>  $lines
     */
    private function parseTabular(array $lines, int $expectedColumns): ParsedTeamFile
    {
        $idColumn = $expectedColumns === 9 ? 0 : 1;
        $siteColumn = $expectedColumns === 9 ? 1 : 2;

        $rows = [];
        $errors = [];

        foreach ($lines as $index => $rawLine) {
            $lineNumber = $index + 1;

            if (trim($rawLine) === '') {
                continue;
            }

            // The .tsv export's version marker. Skipped, not reported: it
            // is a legitimate part of the file, not a malformed row.
            if (str_starts_with($rawLine, 'File_Version')) {
                continue;
            }

            $columns = explode("\t", $rawLine);

            if (count($columns) !== $expectedColumns) {
                $errors[] = [
                    'line' => $lineNumber,
                    'message' => 'esperava '.$expectedColumns.' colunas separadas por TAB, encontrou '.count($columns).'.',
                ];

                continue;
            }

            $columns = array_map(trim(...), $columns);

            $icpcId = $columns[$idColumn];
            $teamName = $columns[3];
            $institution = $columns[4];
            $institutionShort = $columns[5];

            // Checked before composing, not after. The composite below
            // would turn an empty team name into " - UENP", which is a
            // perfectly non-empty string and would sail past the generic
            // emptiness check in buildRow() -- putting a nameless team on
            // the scoreboard.
            if ($teamName === '') {
                $errors[] = ['line' => $lineNumber, 'message' => 'nome da equipe vazio (coluna 4).'];

                continue;
            }

            // BOCA's userfullname for an ICPC import is "team name -
            // institution short name", falling back to the bare team name
            // when the short name column is empty. That composite is what
            // ends up on the scoreboard, so it is reproduced exactly.
            $fullname = $institutionShort !== '' ? $teamName.' - '.$institutionShort : $teamName;

            $row = $this->buildRow(
                line: $lineNumber,
                icpcId: $icpcId,
                username: $icpcId,
                fullname: $fullname,
                description: $institution !== '' ? $institution : null,
                siteNumber: $columns[$siteColumn],
                errors: $errors,
            );

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return new ParsedTeamFile($rows, $errors);
    }

    /**
     * BOCA's third format: a `[user]` marker followed by blocks of
     * `userkey=value` lines separated by blank lines.
     *
     * Only `usertype=team` blocks are imported. A users.txt can declare
     * admins and judges with plaintext passwords, and honouring that here
     * would turn a file an organiser received by email into a way to mint
     * privileged accounts with known credentials -- a considerably bigger
     * decision than "import the team list", and not one issue #141 asks
     * for. Non-team blocks are reported, never silently dropped.
     *
     * @param  list<string>  $lines
     */
    private function parseBocaUsers(array $lines): ParsedTeamFile
    {
        $rows = [];
        $errors = [];

        $started = false;
        $block = [];
        $blockLine = 0;

        $flush = function () use (&$block, &$blockLine, &$rows, &$errors) {
            if ($block === []) {
                return;
            }

            $this->appendBocaBlock($block, $blockLine, $rows, $errors);
            $block = [];
            $blockLine = 0;
        };

        foreach ($lines as $index => $rawLine) {
            $lineNumber = $index + 1;
            $line = trim($rawLine);

            if (! $started) {
                if ($line === '[user]') {
                    $started = true;
                }

                continue;
            }

            if ($line === '') {
                $flush();

                continue;
            }

            // Any other bracketed section ends the user list, matching
            // BOCA's `$ar[$i][0] != "["` loop guard.
            if (str_starts_with($line, '[')) {
                $flush();
                break;
            }

            if (! str_starts_with($line, 'user') || ! str_contains($line, '=')) {
                $errors[] = [
                    'line' => $lineNumber,
                    'message' => 'linha fora do formato "userchave=valor": '.mb_strimwidth($line, 0, 60, '...').'.',
                ];

                continue;
            }

            if ($block === []) {
                $blockLine = $lineNumber;
            }

            [$key, $value] = explode('=', $line, 2);
            $block[trim($key)] = trim($value);
        }

        $flush();

        if (! $started) {
            $errors[] = ['line' => 0, 'message' => 'arquivo sem o marcador [user] exigido pelo formato do BOCA.'];
        }

        return new ParsedTeamFile($rows, $errors);
    }

    /**
     * @param  array<string, string>  $block
     * @param  list<ParsedTeamRow>  $rows
     * @param  list<array{line: int, message: string}>  $errors
     */
    private function appendBocaBlock(array $block, int $blockLine, array &$rows, array &$errors): void
    {
        // "usertype: type of the user. If not specified, it's set to
        // team." (doc/import-user.txt)
        $type = $block['usertype'] ?? 'team';

        if ($type !== 'team') {
            $errors[] = [
                'line' => $blockLine,
                'message' => "bloco ignorado: usertype='{$type}'. Este comando importa apenas equipes.",
            ];

            return;
        }

        $number = $block['usernumber'] ?? '';
        // "username: nickname of the user. If not specified, it's
        // generated from the usernumber, ie, team+usernumber."
        $username = $block['username'] ?? ($number !== '' ? 'team'.$number : '');

        $row = $this->buildRow(
            line: $blockLine,
            // usericpcid is optional in this format ("Not required"), and
            // usernumber is a BOCA-internal per-site number, not an ICPC
            // id -- conflating the two would make identity() match teams
            // across unrelated files. Left null when absent; identity then
            // falls back to the username.
            icpcId: $block['usericpcid'] ?? '',
            username: $username,
            fullname: $block['userfullname'] ?? '',
            description: ($block['userdesc'] ?? '') !== '' ? $block['userdesc'] : null,
            siteNumber: $block['usersitenumber'] ?? '',
            errors: $errors,
            passwordDiscarded: ($block['userpassword'] ?? '') !== '',
        );

        if ($row !== null) {
            $rows[] = $row;
        }
    }

    /**
     * Shared validation for every format. Returns null (after appending to
     * $errors) rather than throwing, so the caller keeps going through the
     * rest of the file.
     *
     * @param  list<array{line: int, message: string}>  $errors
     */
    private function buildRow(
        int $line,
        string $icpcId,
        string $username,
        string $fullname,
        ?string $description,
        string $siteNumber,
        array &$errors,
        bool $passwordDiscarded = false,
    ): ?ParsedTeamRow {
        $problems = [];

        if (trim($fullname) === '') {
            $problems[] = 'nome da equipe vazio';
        } elseif (mb_strlen($fullname) > self::MAX_FULLNAME) {
            // mb_strlen, not strlen: "Universidade Estadual do Norte do
            // Parana" is shorter in characters than in bytes, and the
            // column is measured in characters.
            $problems[] = 'nome completo com '.mb_strlen($fullname).' caracteres (maximo '.self::MAX_FULLNAME.')';
        }

        if (trim($username) === '') {
            $problems[] = 'sem identificador de usuario (id da equipe ou username)';
        } elseif (mb_strlen($username) > self::MAX_USERNAME) {
            $problems[] = 'usuario com '.mb_strlen($username).' caracteres (maximo '.self::MAX_USERNAME.')';
        }

        if (mb_strlen($icpcId) > self::MAX_ICPC_ID) {
            $problems[] = 'id ICPC com '.mb_strlen($icpcId).' caracteres (maximo '.self::MAX_ICPC_ID.')';
        }

        // The .tsv format writes a literal "null" in its unused first
        // column; a site column that says the same is a missing value, not
        // a site named "null".
        $site = null;
        if ($siteNumber !== '' && mb_strtolower($siteNumber) !== 'null') {
            if (! ctype_digit($siteNumber)) {
                $problems[] = "site '{$siteNumber}' nao e um numero";
            } else {
                $site = (int) $siteNumber;
            }
        }

        if ($problems !== []) {
            $errors[] = ['line' => $line, 'message' => implode('; ', $problems).'.'];

            return null;
        }

        return new ParsedTeamRow(
            line: $line,
            icpcId: $icpcId !== '' ? $icpcId : null,
            username: trim($username),
            fullname: trim($fullname),
            description: $description,
            siteNumber: $site,
            passwordDiscarded: $passwordDiscarded,
        );
    }

    /**
     * Everything encoding-related happens here, once, before a single line
     * is looked at.
     *
     * These files come off the ICPC site and then through whatever the
     * organiser's machine does to them, so all three of the following are
     * real and all three corrupt Brazilian university names if ignored:
     *  - a UTF-8 BOM, which would otherwise glue itself to the first team
     *    id and make row 1 a mismatch on every subsequent import;
     *  - CRLF (the shipped BOCA samples are CRLF), whose trailing \r would
     *    land inside the last column's value;
     *  - Windows-1252/ISO-8859-1 content from an older export or a
     *    spreadsheet round-trip, where "Parana" arrives as a single 0xE1
     *    byte that is not valid UTF-8 and would be rejected or mangled by
     *    the database.
     *
     * mb_check_encoding is the discriminator rather than a charset option:
     * valid UTF-8 is left strictly untouched (converting it "from"
     * Windows-1252 would double-encode every accent), and only content
     * that cannot be UTF-8 is reinterpreted.
     */
    private function normalise(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $contents = str_replace(["\r\n", "\r"], "\n", $contents);

        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        return $contents;
    }
}
