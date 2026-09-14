<?php

namespace App\Services\EventImport;

use App\Models\Language;
use App\Support\IpAccessList;
use Carbon\Carbon;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Issue #147 -- reads the single declarative file that describes a whole
 * event (contest, sedes, linguagens, problemas) and turns it into a
 * ParsedEvent.
 *
 * WHY NOT BOCA'S XML. BOCA provisions a contest from src/system/importxml.php,
 * and compatibility with BOCA is this project's theme, so the obvious move
 * would be to read that XML. The issue argues otherwise and this
 * implementation follows it: the file that describes a regional is written
 * by hand, lives in git next to the problem packages, and is reviewed as a
 * diff between editions ("a sede X mudou de coordenador", "o congelamento
 * caiu para 45min"). XML reviews badly in a diff and is miserable to type;
 * YAML and JSON are both fine. Reading BOCA's XML remains reasonable as an
 * *additional* front end -- it would produce the same ParsedEvent and reuse
 * everything below it -- and nothing here forecloses that.
 *
 * WHY TWO FORMATS. JSON is parsed by PHP itself and therefore works in the
 * production image, which runs `composer install --no-dev`. symfony/yaml is
 * currently a dev-only dependency of this project (it arrives via
 * laravel/sail), so YAML is accepted whenever the class is present and
 * refused with an explicit instruction when it is not -- never silently.
 * Promoting symfony/yaml to a production dependency is a one-line composer
 * change and is the recommended follow-up; until then the format that is
 * guaranteed to work on the contest server is JSON.
 *
 * The parser never touches the database and never throws for bad input.
 * Identity, "does this already exist" and every rule that needs a query
 * live in EventImporter; this class only decides whether the file says
 * something well formed.
 */
class EventFileParser
{
    public const FORMAT_JSON = 'json';

    public const FORMAT_YAML = 'yaml';

    public const FORMATS = [self::FORMAT_JSON, self::FORMAT_YAML];

    /**
     * Bumped only when a file written for version 1 would be misread. It is
     * required rather than optional so that version 2 can tell "an old file"
     * from "a file that forgot the key" -- a distinction that cannot be
     * recovered after the fact.
     */
    public const VERSION = 1;

    /** Top-level blocks. Anything else in the file is a typo. */
    private const BLOCKS = ['version', 'contest', 'sites', 'languages', 'problems'];

    /**
     * Extension first, content second -- the opposite of TeamFileParser,
     * and for a good reason: there the extension lies routinely (the ICPC
     * download gets saved as teams.txt), whereas here the operator names
     * the file themselves and `.yml` versus `.json` is a deliberate choice.
     * The content sniff only has to cover the one honest mistake, an event
     * file saved as `.txt`.
     */
    public function detectFormat(string $contents, string $filename = ''): ?string
    {
        $extension = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($extension === 'json') {
            return self::FORMAT_JSON;
        }

        if (in_array($extension, ['yml', 'yaml'], true)) {
            return self::FORMAT_YAML;
        }

        $trimmed = ltrim($contents);

        if (str_starts_with($trimmed, '{')) {
            return self::FORMAT_JSON;
        }

        return $trimmed === '' ? null : self::FORMAT_YAML;
    }

    /**
     * @param  string  $baseDir  directory the file lives in; relative paths inside it (problem packages) resolve against it
     */
    public function parse(string $contents, string $format, string $baseDir = ''): ParsedEvent
    {
        $decoded = $this->decode($contents, $format);

        if (is_array($decoded) && isset($decoded['__error'])) {
            return ParsedEvent::failed([$decoded['__error']]);
        }

        if (! $this->isMap($decoded)) {
            return ParsedEvent::failed([[
                'location' => 'arquivo',
                'message' => 'o arquivo deve conter um mapa com as chaves '.implode(', ', self::BLOCKS).'.',
            ]]);
        }

        $errors = [];
        $warnings = [];

        foreach (array_keys($decoded) as $key) {
            if (! in_array((string) $key, self::BLOCKS, true)) {
                $errors[] = ['location' => (string) $key, 'message' => "bloco desconhecido \"{$key}\". Blocos validos: ".implode(', ', self::BLOCKS).'.'];
            }
        }

        $version = $decoded['version'] ?? null;
        if ($version === null) {
            $errors[] = ['location' => 'version', 'message' => 'campo obrigatorio ausente. Use "version: '.self::VERSION.'".'];
        } elseif ($version !== self::VERSION) {
            $errors[] = ['location' => 'version', 'message' => 'versao de formato nao suportada: '.json_encode($version).'. Este microHelium le version '.self::VERSION.'.'];
        }

        $contest = $this->parseContest($decoded['contest'] ?? null, $errors, $warnings);
        $sites = $this->parseSites($decoded['sites'] ?? null, $errors, $warnings);
        $languages = $this->parseLanguages($decoded['languages'] ?? null, $errors, $warnings);
        $problems = $this->parseProblems($decoded['problems'] ?? null, $baseDir, $errors, $warnings);

        return new ParsedEvent($contest, $sites, $languages, $problems, $errors, $warnings);
    }

    /**
     * @return array<string, mixed>|array{__error: array{location: string, message: string}}
     */
    private function decode(string $contents, string $format): array
    {
        if ($format === self::FORMAT_JSON) {
            $decoded = json_decode($contents, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                // json_decode reports no offset, so unlike the YAML branch
                // there is no line to point at. Saying so is better than
                // pretending: the operator needs to know the file was not
                // read at all, and which tool will find the spot.
                return ['__error' => [
                    'location' => 'arquivo',
                    'message' => 'JSON invalido: '.json_last_error_msg().'. Nenhuma linha pode ser indicada -- '
                        .'valide o arquivo (por exemplo com `python3 -m json.tool arquivo.json`) ou use YAML, que aponta a linha.',
                ]];
            }

            return is_array($decoded) ? $decoded : ['__error' => [
                'location' => 'arquivo',
                'message' => 'o arquivo deve conter um objeto JSON no nivel mais externo.',
            ]];
        }

        if (! class_exists(Yaml::class)) {
            return ['__error' => [
                'location' => 'arquivo',
                'message' => 'suporte a YAML indisponivel nesta instalacao (symfony/yaml nao esta instalado). '
                    .'Converta o arquivo para JSON ou instale a dependencia (composer require symfony/yaml).',
            ]];
        }

        try {
            $decoded = Yaml::parse($contents);
        } catch (ParseException $e) {
            // Symfony does give a line, which is the whole reason a YAML
            // syntax error is friendlier than a JSON one here.
            $line = $e->getParsedLine();

            return ['__error' => [
                'location' => $line > 0 ? "linha {$line}" : 'arquivo',
                'message' => 'YAML invalido: '.$e->getMessage(),
            ]];
        }

        return is_array($decoded) ? $decoded : ['__error' => [
            'location' => 'arquivo',
            'message' => 'o arquivo esta vazio ou nao contem um mapa YAML.',
        ]];
    }

    /**
     * @param  list<array{location: string, message: string}>  $errors
     * @param  list<array{location: string, message: string}>  $warnings
     * @return array<string, mixed>
     */
    private function parseContest(mixed $raw, array &$errors, array &$warnings): array
    {
        if ($raw === null) {
            $errors[] = ['location' => 'contest', 'message' => 'bloco obrigatorio ausente.'];

            return [];
        }

        if (! $this->isMap($raw)) {
            $errors[] = ['location' => 'contest', 'message' => 'esperado um mapa com os dados da competicao.'];

            return [];
        }

        $reader = new FieldReader($raw, 'contest');

        $attributes = [
            'name' => $reader->string('name', null, 100, required: true),
            'description' => $reader->string('description', null, 65535),
            'duration' => $reader->int('duration', 300, 1, 10080),
            'freeze_time' => $reader->int('freeze_time', 60, 0, 10080),
            'penalty' => $reader->int('penalty', 20, 0, 120),
            'max_file_size' => $reader->int('max_file_size', 100, 1, 10240),
            'is_active' => $reader->bool('is_active', false),
            'is_public' => $reader->bool('is_public', false),
            'unlock_key' => $reader->string('unlock_key', null, 100),
            'start_time' => null,
        ];

        $attributes['start_time'] = $this->parseStartTime($reader, $errors, $warnings);

        // is_practice is a column but deliberately not a field: the practice
        // contest is a single technical row owned by PracticeContest (#43),
        // not an event, and an import that flipped the flag would hide a
        // real contest from every screen that means "a competition".
        if (array_key_exists('is_practice', $raw)) {
            $errors[] = ['location' => 'contest.is_practice', 'message' => 'campo nao importavel: o contest tecnico do Treino Livre (#43) nao e criado por arquivo.'];
            $reader->ignore('is_practice');
        }

        $reader->rejectUnknownKeys();
        $errors = array_merge($errors, $reader->errors());

        if (is_int($attributes['freeze_time']) && is_int($attributes['duration']) && $attributes['freeze_time'] > $attributes['duration']) {
            // A warning, not an error: it is almost certainly a mistake
            // (the scoreboard would be frozen from before the start), but
            // it is a legal configuration and refusing it would be this
            // importer deciding it knows the contest better than its
            // director.
            $warnings[] = [
                'location' => 'contest.freeze_time',
                'message' => "congelamento ({$attributes['freeze_time']}min) maior que a duracao ({$attributes['duration']}min): o placar nasce congelado.",
            ];
        }

        return $attributes;
    }

    /**
     * The one field where getting the timezone wrong is the failure the
     * issue names out loud ("sede com fuso errado desloca o horario de
     * congelamento daquela sede"), so it gets treated carefully:
     *
     *  - the value is parsed with whatever offset it carries and then
     *    converted to the application timezone before being handed to
     *    Eloquent. Eloquent formats a Carbon with Y-m-d H:i:s *in whatever
     *    zone the instance happens to hold*; without the conversion,
     *    "2026-09-19T13:00:00-03:00" would be written to the column as
     *    13:00 and the contest would start three hours late;
     *  - a value with no offset at all is accepted but warned about, since
     *    "09:00" means different instants to the person who typed it in
     *    Manaus and to the server that reads it as UTC.
     *
     * @param  list<array{location: string, message: string}>  $errors
     * @param  list<array{location: string, message: string}>  $warnings
     */
    private function parseStartTime(FieldReader $reader, array &$errors, array &$warnings): ?Carbon
    {
        $raw = $reader->string('start_time', null, 64);

        if ($raw === null) {
            return null;
        }

        try {
            $parsed = Carbon::parse($raw);
        } catch (\Throwable $e) {
            $errors[] = ['location' => 'contest.start_time', 'message' => "data/hora invalida: \"{$raw}\". Use ISO 8601 com fuso, ex: 2026-09-19T13:00:00-03:00."];

            return null;
        }

        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $raw) !== 1) {
            $warnings[] = [
                'location' => 'contest.start_time',
                'message' => "\"{$raw}\" nao traz fuso horario; foi lido como ".config('app.timezone')
                    .'. Escreva o fuso (ex: 2026-09-19T13:00:00-03:00) para nao depender da configuracao do servidor.',
            ];
        }

        return $parsed->setTimezone(config('app.timezone'));
    }

    /**
     * @param  list<array{location: string, message: string}>  $errors
     * @param  list<array{location: string, message: string}>  $warnings
     * @return list<EventEntry>
     */
    private function parseSites(mixed $raw, array &$errors, array &$warnings): array
    {
        $items = $this->blockItems($raw, 'sites', $errors, $warnings, required: true);

        $entries = [];

        foreach ($items as $index => $item) {
            $location = "sites[{$index}]";

            if (! $this->isMap($item)) {
                $errors[] = ['location' => $location, 'message' => 'esperado um mapa com os dados da sede.'];

                continue;
            }

            $reader = new FieldReader($item, $location);

            $attributes = [
                'name' => $reader->string('name', null, 100, required: true),
                'ip_address' => $reader->string('ip_address', null, 200),
                'is_active' => $reader->bool('is_active', true),
                'permit_logins' => $reader->bool('permit_logins', true),
                'auto_judge' => $reader->bool('auto_judge', false),
                // Null is meaningful here and is the default: the site
                // inherits the contest's duration/freeze. A number is a
                // per-site override, which is how a regional gives one
                // remote site a different schedule.
                'duration' => $reader->int('duration', null, 1, 10080, nullable: true),
                'freeze_time' => $reader->int('freeze_time', null, 0, 10080, nullable: true),
                'max_runtime' => $reader->int('max_runtime', 600, 1, 86400),
                'chief_judge_name' => $reader->string('chief_judge_name', null, 50),
                'score_visibility' => $reader->enum('score_visibility', ['all', 'own_site'], 'all'),
                'max_judge_wait_time' => $reader->int('max_judge_wait_time', 900, 60, 86400),
            ];

            // judges_for is not a column: it becomes site_judging_routes
            // rows, written by name because a file cannot know the ids the
            // database will hand out.
            $extras = [];
            $judgesFor = $reader->stringList('judges_for', 100);
            if ($judgesFor !== null) {
                $extras['judges_for'] = $judgesFor;
            }

            $reader->rejectUnknownKeys();
            $errors = array_merge($errors, $reader->errors());

            if ($ipError = IpAccessList::firstError($attributes['ip_address'])) {
                $errors[] = ['location' => $location.'.ip_address', 'message' => $ipError];
            }

            if ($attributes['name'] === null) {
                continue;
            }

            $entries[] = new EventEntry($location, $attributes['name'], $attributes, $extras);
        }

        return $entries;
    }

    /**
     * @param  list<array{location: string, message: string}>  $errors
     * @param  list<array{location: string, message: string}>  $warnings
     * @return list<EventEntry>
     */
    private function parseLanguages(mixed $raw, array &$errors, array &$warnings): array
    {
        $items = $this->blockItems($raw, 'languages', $errors, $warnings, required: false);

        // The built-in catalog, keyed by the identifier the file uses. It is
        // read from Language::getDefaultLanguages() rather than copied so
        // that a file saying `preset: cpp_gpp13` gets the same compile line
        // the contest wizard would have given it -- and keeps getting it
        // when the catalog is corrected.
        $catalog = collect(Language::getDefaultLanguages())->keyBy('extension');

        $entries = [];

        foreach ($items as $index => $item) {
            $location = "languages[{$index}]";

            if (! $this->isMap($item)) {
                $errors[] = ['location' => $location, 'message' => 'esperado um mapa com os dados da linguagem.'];

                continue;
            }

            $reader = new FieldReader($item, $location);

            $preset = $reader->string('preset', null, 20);
            $defaults = ['name' => null, 'compile_command' => null, 'run_command' => null];

            if ($preset !== null) {
                if (! $catalog->has($preset)) {
                    $errors[] = ['location' => $location.'.preset', 'message' => "preset desconhecido \"{$preset}\". Consulte a tela de linguagens para os identificadores disponiveis, ou descreva a linguagem por extenso (name, extension, compile_command, run_command)."];

                    continue;
                }

                $entry = $catalog->get($preset);
                $defaults = [
                    'name' => $entry['name'],
                    'compile_command' => $entry['compile_command'],
                    'run_command' => $entry['run_command'],
                ];
            }

            // Read unconditionally so the key counts as known either way;
            // required only when there is no preset to take it from.
            $extension = $reader->string('extension', null, 20, required: $preset === null, pattern: '/^[A-Za-z0-9_]+$/', patternHint: 'o identificador aceita apenas letras, numeros e underscore (ex: cpp_gpp13).');

            if ($preset !== null && $extension !== null && $extension !== $preset) {
                $errors[] = ['location' => $location.'.extension', 'message' => "conflito: preset \"{$preset}\" e extension \"{$extension}\". O preset ja define o identificador -- informe um ou outro."];

                continue;
            }

            $attributes = [
                // A language taken from a preset may still be renamed or
                // have its command tuned; anything not given falls back to
                // the catalog entry.
                'name' => $reader->string('name', $defaults['name'], 50, required: $preset === null, pattern: '/^[^~]/', patternHint: 'o nome nao pode comecar com "~" (reservado para renomeacoes internas).'),
                'extension' => $preset ?? $extension,
                'compile_command' => $reader->string('compile_command', $defaults['compile_command'], 2000, required: $preset === null),
                'run_command' => $reader->string('run_command', $defaults['run_command'], 2000, required: $preset === null),
                'is_active' => $reader->bool('is_active', true),
            ];

            $reader->rejectUnknownKeys();
            $errors = array_merge($errors, $reader->errors());

            if ($attributes['extension'] === null || $attributes['name'] === null) {
                continue;
            }

            $entries[] = new EventEntry($location, $attributes['extension'], $attributes);
        }

        return $entries;
    }

    /**
     * @param  list<array{location: string, message: string}>  $errors
     * @param  list<array{location: string, message: string}>  $warnings
     * @return list<EventEntry>
     */
    private function parseProblems(mixed $raw, string $baseDir, array &$errors, array &$warnings): array
    {
        $items = $this->blockItems($raw, 'problems', $errors, $warnings, required: false);

        $entries = [];

        foreach ($items as $index => $item) {
            $location = "problems[{$index}]";

            if (! $this->isMap($item)) {
                $errors[] = ['location' => $location, 'message' => 'esperado um mapa com os dados do problema.'];

                continue;
            }

            $reader = new FieldReader($item, $location);

            $attributes = [
                'short_name' => $reader->string('short_name', null, 10, required: true, pattern: '/^[A-Za-z0-9_-]+$/', patternHint: 'a letra do problema aceita apenas letras, numeros, "_" e "-".'),
                'name' => $reader->string('name', null, 200, required: true),
                // basename is concatenated into a filesystem path by
                // Problem::getPackagePath(); anything that could climb out
                // of storage/app/problems is rejected here rather than
                // discovered later by the judge.
                'basename' => $reader->string('basename', null, 100, required: true, pattern: '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', patternHint: 'o basename e um nome de diretorio: comece com letra ou numero e use apenas letras, numeros, ".", "_" e "-".'),
                'description' => $reader->string('description', null, 65535),
                'color_name' => $reader->string('color_name', null, 50),
                'color_hex' => $reader->string('color_hex', null, 32, pattern: '/^#[0-9A-Fa-f]{6}$/', patternHint: 'a cor deve ser um hexadecimal de 6 digitos, ex: #EF4444.'),
                'time_limit' => $reader->int('time_limit', 1, 1, 3600),
                'memory_limit' => $reader->int('memory_limit', 256, 1, 65536),
                'output_limit' => $reader->int('output_limit', 1024, 1, 1048576),
                'auto_judge' => $reader->bool('auto_judge', true),
                'is_fake' => $reader->bool('is_fake', false),
                // Default: the order the problems appear in the file. That
                // is the whole point of a declarative file -- the list is
                // the order.
                'sort_order' => $reader->int('sort_order', $index + 1, 0, 1000),
            ];

            $extras = [];
            $package = $reader->string('package', null, 4096);
            if ($package !== null) {
                // Resolved against the file's own directory so that an
                // event repository (event.yml next to packages/*.zip) can
                // be cloned anywhere.
                $resolved = $this->resolvePath($package, $baseDir);

                if (! is_file($resolved) || ! is_readable($resolved)) {
                    $errors[] = ['location' => $location.'.package', 'message' => "pacote nao encontrado ou sem permissao de leitura: {$resolved}"];
                } else {
                    $extras['package'] = $resolved;
                }
            }

            $reader->rejectUnknownKeys();
            $errors = array_merge($errors, $reader->errors());

            if ($attributes['basename'] === null || $attributes['short_name'] === null || $attributes['name'] === null) {
                continue;
            }

            $entries[] = new EventEntry($location, $attributes['basename'], $attributes, $extras);
        }

        return $entries;
    }

    /**
     * @param  list<array{location: string, message: string}>  $errors
     * @param  list<array{location: string, message: string}>  $warnings
     * @return array<int, mixed>
     */
    private function blockItems(mixed $raw, string $block, array &$errors, array &$warnings, bool $required): array
    {
        if ($raw === null) {
            if ($required) {
                $errors[] = ['location' => $block, 'message' => 'bloco obrigatorio ausente: uma competicao sem sede nao aceita login de ninguem.'];
            } else {
                // Not an error: a file may legitimately describe only the
                // contest and its sites (problems arriving later, from the
                // problem bank). Said out loud so that a block lost to a
                // bad indent does not pass as a deliberate omission.
                $warnings[] = ['location' => $block, 'message' => "bloco ausente: nada sera criado em \"{$block}\"."];
            }

            return [];
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            $errors[] = ['location' => $block, 'message' => 'esperada uma lista.'];

            return [];
        }

        if ($raw === [] && $required) {
            $errors[] = ['location' => $block, 'message' => 'lista vazia: uma competicao sem sede nao aceita login de ninguem.'];
        }

        return $raw;
    }

    /**
     * A mapping rather than a sequence. An empty array counts as a mapping:
     * both YAML and JSON decode `{}` to [], and reporting "esperado um mapa"
     * for an empty one would bury the real complaint (the required fields
     * it is missing).
     *
     * @phpstan-assert-if-true array<string, mixed> $value
     */
    private function isMap(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    private function resolvePath(string $path, string $baseDir): string
    {
        if ($path === '' || $path[0] === '/' || $baseDir === '') {
            return $path;
        }

        return rtrim($baseDir, '/').'/'.$path;
    }
}
