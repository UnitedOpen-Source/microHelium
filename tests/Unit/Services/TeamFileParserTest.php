<?php

namespace Tests\Unit\Services;

use App\Services\TeamImport\TeamFileParser;
use Tests\TestCase;

/**
 * Issue #141 -- the three team-file formats BOCA documents in
 * doc/import-user.txt, read straight off the fixtures in
 * tests/Fixtures/team-import (real ICPC rows, CRLF, accented Brazilian
 * university names, one deliberately malformed line each).
 *
 * These assertions are about the field *positions*, which are the easiest
 * thing to get wrong and the hardest to notice: a team name that silently
 * became an institution name still imports cleanly and only shows up on the
 * scoreboard on contest day.
 */
class TeamFileParserTest extends TestCase
{
    private TeamFileParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new TeamFileParser;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/team-import/'.$name);
    }

    public function test_pc2_tab_maps_the_nine_columns_the_way_boca_does()
    {
        $parsed = $this->parser->parse($this->fixture('regional.tab'), TeamFileParser::FORMAT_PC2_TAB);

        $first = $parsed->rows[0];

        $this->assertSame('1783', $first->icpcId);
        $this->assertSame('1783', $first->username);
        // Team name plus institution SHORT name -- BOCA's userfullname.
        $this->assertSame('Garotos de Programa - UENP', $first->fullname);
        // Institution LONG name -- BOCA's userdesc. Accents intact.
        $this->assertSame('Universidade Estadual do Norte do Paraná', $first->description);
        $this->assertSame(1301, $first->siteNumber);
        $this->assertSame(1, $first->line);
    }

    public function test_utf8_survives_crlf_and_the_row_after_a_malformed_one_is_still_read()
    {
        $parsed = $this->parser->parse($this->fixture('regional.tab'), TeamFileParser::FORMAT_PC2_TAB);

        // 5 lines, line 4 is short. The file is not abandoned there: the
        // 5th line still arrives, which is the whole point of collecting
        // errors instead of breaking out of the loop like BOCA does.
        $this->assertCount(4, $parsed->rows);
        $this->assertSame([1783, 1533, 1892, 1065], array_map(fn ($r) => (int) $r->icpcId, $parsed->rows));

        $this->assertCount(1, $parsed->errors);
        $this->assertSame(4, $parsed->errors[0]['line']);
        $this->assertStringContainsString('esperava 9 colunas', $parsed->errors[0]['message']);

        // CRLF means the last column of every row ends in \r unless it is
        // stripped; assert on a row whose institution is the last thing we
        // keep, and on an accented one.
        $this->assertSame('Física Computacional - IFSC-USP', $parsed->rows[2]->fullname);
        $this->assertSame('IFSC - Instituto de Física de São Carlos - USP', $parsed->rows[2]->description);
    }

    public function test_a_row_flagged_n_is_still_imported_matching_boca()
    {
        // Column 8 of the .tab format is read by nobody, BOCA included.
        // Pinned by a test because "helpfully" filtering these out later
        // would silently drop teams from a regional.
        $parsed = $this->parser->parse($this->fixture('regional.tab'), TeamFileParser::FORMAT_PC2_TAB);

        $this->assertContains('1065', array_map(fn ($r) => $r->icpcId, $parsed->rows));
    }

    public function test_windows_1252_files_are_decoded_rather_than_mangled()
    {
        // Older ICPC exports and spreadsheet round-trips arrive as
        // Windows-1252, where "Paraná" is a single 0xE1 byte.
        $raw = $this->fixture('latin1.tab');
        $this->assertFalse(mb_check_encoding($raw, 'UTF-8'), 'fixture is meant to be invalid UTF-8');

        $parsed = $this->parser->parse($raw, TeamFileParser::FORMAT_PC2_TAB);

        $this->assertSame('Universidade Estadual do Norte do Paraná', $parsed->rows[0]->description);
    }

    public function test_valid_utf8_is_never_double_encoded()
    {
        $parsed = $this->parser->parse($this->fixture('regional.tab'), TeamFileParser::FORMAT_PC2_TAB);

        // The failure this guards against is converting "from"
        // Windows-1252 unconditionally, which turns "á" into "Ã¡".
        $this->assertStringNotContainsString('Ã', $parsed->rows[0]->description);
    }

    public function test_a_utf8_bom_does_not_end_up_glued_to_the_first_team_id()
    {
        $parsed = $this->parser->parse("\xEF\xBB\xBF".$this->fixture('regional.tab'), TeamFileParser::FORMAT_PC2_TAB);

        $this->assertSame('1783', $parsed->rows[0]->icpcId);
    }

    public function test_teams_tsv_skips_its_version_header_and_uses_the_other_column_order()
    {
        $parsed = $this->parser->parse($this->fixture('Teams.tsv'), TeamFileParser::FORMAT_TEAMS_TSV);

        $this->assertCount(3, $parsed->rows);
        $this->assertSame([], $parsed->errors, 'File_Version is part of the format, not a bad row');

        $second = $parsed->rows[1];
        $this->assertSame('1487', $second->icpcId);
        $this->assertSame('Multi-thread Simultâneo (MTS) - EACH-USP', $second->fullname);
        // Column 2 is the site here, not column 1 as in the .tab format.
        $this->assertSame(2, $second->siteNumber);
        // Line 1 is the header, so the first data row is line 2.
        $this->assertSame(3, $second->line);
    }

    public function test_boca_users_txt_imports_teams_and_refuses_other_user_types()
    {
        $parsed = $this->parser->parse($this->fixture('users.txt'), TeamFileParser::FORMAT_BOCA_USERS);

        $this->assertCount(2, $parsed->rows);

        // No `username=` in the first block: doc/import-user.txt says it is
        // then generated as "team" + usernumber.
        $this->assertSame('team1', $parsed->rows[0]->username);
        $this->assertSame('[PUC-SP A] Equipe Ação', $parsed->rows[0]->fullname);
        $this->assertSame('9001', $parsed->rows[0]->icpcId);

        $this->assertSame('equipe-tres', $parsed->rows[1]->username);
        $this->assertSame('Equipe Três', $parsed->rows[1]->fullname);
        // The file supplied a plaintext password and it was thrown away.
        $this->assertTrue($parsed->rows[1]->passwordDiscarded);

        // The judge block is reported, not silently dropped -- and not
        // imported, because a file an organiser received by email must not
        // be able to mint a privileged account with a known password.
        $this->assertCount(1, $parsed->errors);
        $this->assertStringContainsString("usertype='judge'", $parsed->errors[0]['message']);
    }

    public function test_format_detection_reads_content_not_the_file_extension()
    {
        // The same three files saved under names that would fool BOCA's
        // extension-only check.
        $this->assertSame(
            TeamFileParser::FORMAT_TEAMS_TSV,
            $this->parser->detectFormat($this->fixture('Teams.tsv'), 'equipes.txt')
        );
        $this->assertSame(
            TeamFileParser::FORMAT_BOCA_USERS,
            $this->parser->detectFormat($this->fixture('users.txt'), 'equipes.tab')
        );
        $this->assertSame(
            TeamFileParser::FORMAT_PC2_TAB,
            $this->parser->detectFormat($this->fixture('regional.tab'), 'whatever')
        );
        $this->assertNull($this->parser->detectFormat("nada\naqui\n", 'mistero.dat'));
    }

    public function test_rows_missing_a_team_name_are_reported_with_their_line_number()
    {
        $file = "1\t1\tA\t\tUniversidade X\tUX\turl\tBrazil\tY\n";

        $parsed = $this->parser->parse($file, TeamFileParser::FORMAT_PC2_TAB);

        $this->assertSame([], $parsed->rows);
        $this->assertSame(1, $parsed->errors[0]['line']);
        $this->assertStringContainsString('nome da equipe vazio', $parsed->errors[0]['message']);
    }

    public function test_a_non_numeric_site_column_is_an_error_not_a_silent_zero()
    {
        $file = "1\tXPTO\tA\tEquipe\tUniversidade X\tUX\turl\tBrazil\tY\n";

        $parsed = $this->parser->parse($file, TeamFileParser::FORMAT_PC2_TAB);

        $this->assertSame([], $parsed->rows);
        $this->assertStringContainsString("site 'XPTO' nao e um numero", $parsed->errors[0]['message']);
    }
}
