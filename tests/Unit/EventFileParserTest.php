<?php

namespace Tests\Unit;

use App\Services\EventImport\EventFileParser;
use Tests\TestCase;

/**
 * Issue #147 -- the reading half of the event importer, with no database in
 * sight. EventImportCommandTest covers what happens to a well-formed file;
 * this covers what the parser says about a file that is not.
 */
class EventFileParserTest extends TestCase
{
    private EventFileParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new EventFileParser;
    }

    /**
     * @return list<string>
     */
    private function errorsOf(string $contents, string $format = 'yaml'): array
    {
        $parsed = $this->parser->parse($contents, $format);

        return array_map(fn (array $e) => $e['location'].': '.$e['message'], $parsed->errors);
    }

    /**
     * @return list<string>
     */
    private function warningsOf(string $contents, string $format = 'yaml'): array
    {
        $parsed = $this->parser->parse($contents, $format);

        return array_map(fn (array $e) => $e['location'].': '.$e['message'], $parsed->warnings);
    }

    private function minimal(string $extra = ''): string
    {
        return "version: 1\ncontest:\n  name: \"Prova\"\nsites:\n  - name: \"Sede\"\n".$extra;
    }

    public function test_the_extension_decides_the_format_and_content_is_the_fallback()
    {
        $this->assertSame('json', $this->parser->detectFormat('irrelevante', 'evento.json'));
        $this->assertSame('yaml', $this->parser->detectFormat('irrelevante', 'evento.yml'));
        $this->assertSame('yaml', $this->parser->detectFormat('irrelevante', 'evento.YAML'));

        // Saved without the usual extension: the content decides.
        $this->assertSame('json', $this->parser->detectFormat('{"version": 1}', 'evento.txt'));
        $this->assertSame('yaml', $this->parser->detectFormat("version: 1\n", 'evento.txt'));
        $this->assertNull($this->parser->detectFormat('', 'evento.txt'));
    }

    /**
     * The failure the issue is really about: a field name that is almost
     * right. Silently ignoring it produces a contest configured differently
     * from the file everyone reviewed.
     */
    public function test_an_unknown_field_is_an_error_not_something_to_ignore()
    {
        $errors = $this->errorsOf("version: 1\ncontest:\n  name: \"Prova\"\n  freze_time: 45\nsites:\n  - name: \"Sede\"\n");

        $this->assertContains('contest.freze_time: campo desconhecido "freze_time". Remova-o ou corrija a grafia.', $errors);
    }

    public function test_an_unknown_top_level_block_is_an_error()
    {
        $errors = $this->errorsOf($this->minimal("equipes:\n  - nome: \"X\"\n"));

        $this->assertStringContainsString('bloco desconhecido "equipes"', implode("\n", $errors));
    }

    public function test_the_format_version_is_required_and_checked()
    {
        $this->assertStringContainsString(
            'version: campo obrigatorio ausente',
            implode("\n", $this->errorsOf("contest:\n  name: \"Prova\"\nsites:\n  - name: \"Sede\"\n")),
        );

        $this->assertStringContainsString(
            'versao de formato nao suportada: 2',
            implode("\n", $this->errorsOf("version: 2\ncontest:\n  name: \"Prova\"\nsites:\n  - name: \"Sede\"\n")),
        );
    }

    public function test_a_contest_without_sites_is_refused()
    {
        $errors = $this->errorsOf("version: 1\ncontest:\n  name: \"Prova\"\n");

        $this->assertStringContainsString('sites: bloco obrigatorio ausente', implode("\n", $errors));
    }

    public function test_an_absent_optional_block_is_a_warning_not_a_silent_omission()
    {
        $warnings = $this->warningsOf($this->minimal());

        $this->assertContains('problems: bloco ausente: nada sera criado em "problems".', $warnings);
        $this->assertContains('languages: bloco ausente: nada sera criado em "languages".', $warnings);
    }

    /**
     * Accepted, because it is a legal configuration; warned about, because
     * it is almost certainly a mistake. The importer does not get to decide
     * it knows the contest better than its director.
     */
    public function test_a_freeze_longer_than_the_contest_is_a_warning()
    {
        $warnings = $this->warningsOf("version: 1\ncontest:\n  name: \"Prova\"\n  duration: 300\n  freeze_time: 400\nsites:\n  - name: \"Sede\"\n");

        $this->assertStringContainsString('o placar nasce congelado', implode("\n", $warnings));
    }

    /**
     * "sede com fuso errado desloca o horario de congelamento daquela sede"
     * -- the issue's own example. An hour with no offset is read in the
     * server's timezone, which is exactly the assumption worth surfacing.
     */
    public function test_a_start_time_without_an_offset_is_warned_about()
    {
        $warnings = $this->warningsOf("version: 1\ncontest:\n  name: \"Prova\"\n  start_time: \"2026-09-19 13:00:00\"\nsites:\n  - name: \"Sede\"\n");

        $this->assertStringContainsString('nao traz fuso horario', implode("\n", $warnings));

        $withOffset = $this->warningsOf("version: 1\ncontest:\n  name: \"Prova\"\n  start_time: \"2026-09-19T13:00:00-03:00\"\nsites:\n  - name: \"Sede\"\n");

        $this->assertSame([], array_filter($withOffset, fn (string $w) => str_contains($w, 'fuso horario')));
    }

    public function test_the_start_time_is_converted_to_the_application_timezone()
    {
        $parsed = $this->parser->parse("version: 1\ncontest:\n  name: \"Prova\"\n  start_time: \"2026-09-19T13:00:00-03:00\"\nsites:\n  - name: \"Sede\"\n", 'yaml');

        $this->assertSame('2026-09-19 16:00:00', $parsed->contest['start_time']->format('Y-m-d H:i:s'));
        $this->assertSame(config('app.timezone'), $parsed->contest['start_time']->getTimezone()->getName());
    }

    public function test_a_preset_and_an_explicit_extension_cannot_disagree()
    {
        $errors = $this->errorsOf($this->minimal("languages:\n  - preset: \"py3\"\n    extension: \"python3\"\n"));

        $this->assertStringContainsString('conflito: preset "py3" e extension "python3"', implode("\n", $errors));
    }

    public function test_a_language_described_by_hand_needs_every_command()
    {
        $errors = $this->errorsOf($this->minimal("languages:\n  - name: \"Meu C\"\n    extension: \"meuc\"\n"));

        $this->assertContains('languages[0].compile_command: campo obrigatorio ausente.', $errors);
        $this->assertContains('languages[0].run_command: campo obrigatorio ausente.', $errors);
    }

    /**
     * basename is concatenated into a filesystem path by
     * Problem::getPackagePath(); the judge then reads and executes things
     * from inside it.
     */
    public function test_a_basename_that_could_escape_the_package_directory_is_refused()
    {
        foreach (['../etc', '/etc/passwd', '.hidden', 'com espaco'] as $basename) {
            $errors = $this->errorsOf($this->minimal("problems:\n  - short_name: \"A\"\n    name: \"P\"\n    basename: \"{$basename}\"\n"));

            $this->assertStringContainsString(
                'problems[0].basename',
                implode("\n", $errors),
                "basename \"{$basename}\" deveria ser recusado",
            );
        }
    }

    public function test_types_are_checked_rather_than_coerced()
    {
        $errors = $this->errorsOf("version: 1\ncontest:\n  name: \"Prova\"\n  penalty: \"vinte\"\n  is_public: \"talvez\"\nsites:\n  - name: \"Sede\"\n    max_judge_wait_time: \"muitos\"\n");

        $this->assertContains('contest.penalty: esperado numero inteiro, encontrado texto.', $errors);
        $this->assertContains('contest.is_public: esperado true ou false, encontrado texto.', $errors);
        $this->assertContains('sites[0].max_judge_wait_time: esperado numero inteiro, encontrado texto.', $errors);
    }

    /**
     * JSON is the format that is guaranteed to work in the production image
     * (composer install --no-dev), so its failure mode has to be honest
     * about what it cannot tell the operator.
     */
    public function test_a_json_syntax_error_says_it_cannot_point_at_a_line()
    {
        $errors = $this->errorsOf('{"version": 1,}', 'json');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('JSON invalido', $errors[0]);
        $this->assertStringContainsString('Nenhuma linha pode ser indicada', $errors[0]);
    }

    public function test_a_yaml_syntax_error_points_at_the_line()
    {
        $errors = $this->errorsOf("version: 1\ncontest:\n  name: \"Prova\"\n   description: torto\n");

        $this->assertCount(1, $errors);
        $this->assertStringStartsWith('linha 4: YAML invalido', $errors[0]);
    }

    public function test_the_practice_flag_is_not_importable()
    {
        $errors = $this->errorsOf("version: 1\ncontest:\n  name: \"Prova\"\n  is_practice: true\nsites:\n  - name: \"Sede\"\n");

        $this->assertStringContainsString('contest.is_practice: campo nao importavel', implode("\n", $errors));
        // Reported once, and not a second time as "campo desconhecido".
        $this->assertCount(1, array_filter($errors, fn (string $e) => str_contains($e, 'is_practice')));
    }
}
