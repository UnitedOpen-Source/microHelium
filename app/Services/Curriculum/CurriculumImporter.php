<?php

namespace App\Services\Curriculum;

use App\Models\CurriculumFramework;
use App\Models\CurriculumOutcome;
use Illuminate\Support\Facades\DB;

/**
 * Issue #396 -- importa um currículo oficial de um CSV com manifesto JSON
 * ao lado (docs/specs/396-curriculos-oficiais.md).
 *
 * Duas fases, e a ordem importa: `parse()` valida o arquivo INTEIRO e só
 * então `import()` escreve, numa transação. Um CSV com erro na linha 140 não
 * deixa 139 habilidades gravadas pela metade.
 *
 * Idempotente: currículo por `slug`, habilidade por (currículo, `code`).
 * Habilidade que está no banco e saiu do CSV é MANTIDA e reportada -- pode
 * estar associada a problemas, e apagar essa associação em silêncio é pior
 * do que manter uma linha a mais.
 */
class CurriculumImporter
{
    public const COLUMNS = ['code', 'stage', 'axis', 'text'];

    private const REQUIRED_COLUMNS = ['code', 'text'];

    private const REQUIRED_MANIFEST = ['slug', 'name', 'jurisdiction', 'version', 'locale', 'source_url', 'source_consulted_at'];

    private const CODE_PATTERN = '/^[A-Za-z0-9._-]{1,32}$/';

    /**
     * @return array{framework: array<string, string|null>, outcomes: list<array{code: string, stage: string|null, axis: string|null, text: string, position: int}>}
     */
    public function parse(string $csvPath): array
    {
        if (! is_file($csvPath) || ! is_readable($csvPath)) {
            throw new CurriculumImportException(["Arquivo não encontrado ou ilegível: {$csvPath}"]);
        }

        $framework = $this->parseManifest(preg_replace('/\.csv$/i', '', $csvPath).'.json');
        $outcomes = $this->parseCsv($csvPath);

        return ['framework' => $framework, 'outcomes' => $outcomes];
    }

    /**
     * @return array{framework: CurriculumFramework, created: int, updated: int, unchanged: int, missing: list<string>}
     */
    public function import(string $csvPath): array
    {
        $parsed = $this->parse($csvPath);

        return DB::transaction(function () use ($parsed) {
            $framework = CurriculumFramework::firstOrNew(['slug' => $parsed['framework']['slug']]);
            $framework->fill($parsed['framework']);
            $framework->save();

            $existing = $framework->outcomes()->get()->keyBy('code');
            $created = $updated = $unchanged = 0;

            foreach ($parsed['outcomes'] as $row) {
                /** @var CurriculumOutcome|null $outcome */
                $outcome = $existing->get($row['code']);
                if ($outcome === null) {
                    $framework->outcomes()->create($row);
                    $created++;

                    continue;
                }

                $outcome->fill($row);
                if ($outcome->isDirty()) {
                    $outcome->save();
                    $updated++;
                } else {
                    $unchanged++;
                }
            }

            $inFile = array_column($parsed['outcomes'], 'code');
            $missing = $existing->keys()
                ->reject(fn ($code) => in_array($code, $inFile, true))
                ->map(fn ($code) => (string) $code)
                ->values()
                ->all();

            return [
                'framework' => $framework,
                'created' => $created,
                'updated' => $updated,
                'unchanged' => $unchanged,
                'missing' => $missing,
            ];
        });
    }

    /**
     * @return array<string, string|null>
     */
    private function parseManifest(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new CurriculumImportException(["Manifesto não encontrado: {$path} (o JSON com os dados do currículo fica ao lado do CSV, com o mesmo nome)."]);
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            throw new CurriculumImportException(["Manifesto não é um objeto JSON válido: {$path}"]);
        }

        $errors = [];
        foreach (self::REQUIRED_MANIFEST as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key]) || trim($data[$key]) === '') {
                $errors[] = "Manifesto: campo obrigatório ausente ou vazio: {$key}";
            }
        }
        foreach (['source_sha256', 'source_notes'] as $key) {
            if (isset($data[$key]) && ! is_string($data[$key])) {
                $errors[] = "Manifesto: {$key} deve ser texto";
            }
        }
        if ($errors === []) {
            if (! preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $data['slug'])) {
                $errors[] = 'Manifesto: slug deve ter só minúsculas, dígitos e hífen (até 64)';
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['source_consulted_at'])
                || ! checkdate((int) substr($data['source_consulted_at'], 5, 2), (int) substr($data['source_consulted_at'], 8, 2), (int) substr($data['source_consulted_at'], 0, 4))) {
                $errors[] = 'Manifesto: source_consulted_at deve ser uma data AAAA-MM-DD';
            }
            if (! filter_var($data['source_url'], FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $data['source_url'])) {
                $errors[] = 'Manifesto: source_url deve ser uma URL http(s)';
            }
            if (isset($data['source_sha256']) && ! preg_match('/^[0-9a-f]{64}$/', $data['source_sha256'])) {
                $errors[] = 'Manifesto: source_sha256 deve ter 64 dígitos hexadecimais minúsculos';
            }
            foreach (['name' => 255, 'jurisdiction' => 16, 'version' => 32, 'locale' => 16, 'source_url' => 2048] as $key => $max) {
                if (mb_strlen($data[$key]) > $max) {
                    $errors[] = "Manifesto: {$key} excede {$max} caracteres";
                }
            }
        }
        if ($errors !== []) {
            throw new CurriculumImportException($errors);
        }

        return [
            'slug' => $data['slug'],
            'name' => trim($data['name']),
            'jurisdiction' => trim($data['jurisdiction']),
            'version' => trim($data['version']),
            'locale' => trim($data['locale']),
            'source_url' => trim($data['source_url']),
            'source_consulted_at' => $data['source_consulted_at'],
            'source_sha256' => $data['source_sha256'] ?? null,
            'source_notes' => isset($data['source_notes']) ? trim($data['source_notes']) : null,
        ];
    }

    /**
     * @return list<array{code: string, stage: string|null, axis: string|null, text: string, position: int}>
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new CurriculumImportException(["Arquivo ilegível: {$path}"]);
        }

        try {
            // escape '' = RFC 4180: aspas se escapam dobrando, e a barra
            // invertida é um caractere como outro qualquer.
            $header = fgetcsv($handle, null, ',', '"', '');
            if ($header === false || $header === [null]) {
                throw new CurriculumImportException(['CSV vazio: falta o cabeçalho (code,stage,axis,text).']);
            }

            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

            $errors = [];
            foreach (array_count_values($header) as $name => $count) {
                if ($count > 1) {
                    $errors[] = "Cabeçalho: coluna repetida: {$name}";
                }
            }
            foreach ($header as $name) {
                if (! in_array($name, self::COLUMNS, true)) {
                    $errors[] = "Cabeçalho: coluna desconhecida: '{$name}' (aceitas: ".implode(', ', self::COLUMNS).')';
                }
            }
            foreach (self::REQUIRED_COLUMNS as $name) {
                if (! in_array($name, $header, true)) {
                    $errors[] = "Cabeçalho: coluna obrigatória ausente: {$name}";
                }
            }
            if ($errors !== []) {
                throw new CurriculumImportException($errors);
            }

            $rows = [];
            $seen = [];
            $line = 1;
            while (($record = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $line++;
                if ($record === [null]) {
                    // Linha em branco (inclusive a do fim do arquivo).
                    continue;
                }
                if (count($record) !== count($header)) {
                    $errors[] = "Linha {$line}: ".count($record).' colunas, o cabeçalho tem '.count($header);

                    continue;
                }

                $values = [];
                $validUtf8 = true;
                foreach ($header as $i => $name) {
                    $value = (string) $record[$i];
                    if (! mb_check_encoding($value, 'UTF-8')) {
                        $validUtf8 = false;
                    }
                    $values[$name] = $value;
                }
                if (! $validUtf8) {
                    $errors[] = "Linha {$line}: texto não é UTF-8 válido";

                    continue;
                }

                $code = trim($values['code']);
                $text = trim((string) preg_replace('/\s+/u', ' ', $values['text']));
                $stage = $this->optional($values['stage'] ?? null);
                $axis = $this->optional($values['axis'] ?? null);

                if ($code === '') {
                    $errors[] = "Linha {$line}: code vazio";
                } elseif (! preg_match(self::CODE_PATTERN, $code)) {
                    $errors[] = "Linha {$line}: code '{$code}' inválido (use letras, dígitos, ponto, hífen ou sublinhado, até 32)";
                } elseif (isset($seen[$code])) {
                    $errors[] = "Linha {$line}: code '{$code}' repetido (já aparece na linha {$seen[$code]})";
                } else {
                    $seen[$code] = $line;
                }
                if ($text === '') {
                    $errors[] = "Linha {$line}: text vazio";
                }
                foreach (['stage' => $stage, 'axis' => $axis] as $name => $value) {
                    if ($value !== null && mb_strlen($value) > 100) {
                        $errors[] = "Linha {$line}: {$name} excede 100 caracteres";
                    }
                }

                $rows[] = [
                    'code' => $code,
                    'stage' => $stage,
                    'axis' => $axis,
                    'text' => $text,
                    'position' => count($rows) + 1,
                ];
            }
        } finally {
            fclose($handle);
        }

        if ($errors === [] && $rows === []) {
            $errors[] = 'CSV sem nenhuma habilidade.';
        }
        if ($errors !== []) {
            throw new CurriculumImportException($errors);
        }

        return $rows;
    }

    private function optional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
