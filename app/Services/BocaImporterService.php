<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class BocaImporterService
{
    protected string $tempPath;
    protected array $errors = [];
    protected array $imported = [];

    public function __construct()
    {
        $this->tempPath = storage_path('app/temp/boca_import');
    }

    /**
     * Import a BOCA problem package from a zip file
     */
    public function importFromZip(string $zipPath, ?int $contestId = null): array
    {
        $this->errors = [];
        $this->imported = [];

        if (!file_exists($zipPath)) {
            $this->errors[] = "Arquivo ZIP nao encontrado: {$zipPath}";
            return $this->getResult();
        }

        // Create temp directory
        if (!is_dir($this->tempPath)) {
            mkdir($this->tempPath, 0755, true);
        }

        $extractPath = $this->tempPath . '/' . uniqid('boca_');

        // Extract ZIP
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $this->errors[] = "Nao foi possivel abrir o arquivo ZIP";
            return $this->getResult();
        }

        $zip->extractTo($extractPath);
        $zip->close();

        // Find problem directories (BOCA structure)
        $problemDirs = $this->findProblemDirectories($extractPath);

        if (empty($problemDirs)) {
            // Maybe it's a single problem package
            if ($this->isBocaProblemDirectory($extractPath)) {
                $problemDirs = [$extractPath];
            } else {
                $this->errors[] = "Nenhum problema BOCA encontrado no arquivo";
                $this->cleanup($extractPath);
                return $this->getResult();
            }
        }

        foreach ($problemDirs as $problemDir) {
            try {
                $problem = $this->importProblem($problemDir, $contestId);
                if ($problem) {
                    $this->imported[] = $problem;
                }
            } catch (\Exception $e) {
                $this->errors[] = "Erro ao importar " . basename($problemDir) . ": " . $e->getMessage();
            }
        }

        // Cleanup
        $this->cleanup($extractPath);

        return $this->getResult();
    }

    /**
     * Import multiple problems from BOCA GitHub examples
     */
    public function importFromBocaGitHub(): array
    {
        $this->errors = [];
        $this->imported = [];

        $problems = [
            'abacaxi' => 'https://raw.githubusercontent.com/cassiopc/boca/master/doc/problemexamples/abacaxi.zip',
            'bits' => 'https://raw.githubusercontent.com/cassiopc/boca/master/doc/problemexamples/bits.zip',
            'formiga' => 'https://raw.githubusercontent.com/cassiopc/boca/master/doc/problemexamples/formiga.zip',
            'multas' => 'https://raw.githubusercontent.com/cassiopc/boca/master/doc/problemexamples/multas.zip',
        ];

        foreach ($problems as $name => $url) {
            try {
                $tempFile = $this->tempPath . '/' . $name . '.zip';

                if (!is_dir($this->tempPath)) {
                    mkdir($this->tempPath, 0755, true);
                }

                // Download the zip file
                $content = @file_get_contents($url);
                if ($content === false) {
                    $this->errors[] = "Nao foi possivel baixar: {$name}";
                    continue;
                }

                file_put_contents($tempFile, $content);

                // Import
                $result = $this->importFromZip($tempFile);
                $this->imported = array_merge($this->imported, $result['imported'] ?? []);
                $this->errors = array_merge($this->errors, $result['errors'] ?? []);

                // Cleanup temp file
                @unlink($tempFile);
            } catch (\Exception $e) {
                $this->errors[] = "Erro ao processar {$name}: " . $e->getMessage();
            }
        }

        return $this->getResult();
    }

    /**
     * Check if directory has BOCA problem structure
     */
    protected function isBocaProblemDirectory(string $path): bool
    {
        $requiredDirs = ['input', 'output'];
        $optionalDirs = ['description', 'limits', 'compare', 'compile', 'run', 'tests'];

        $foundRequired = 0;
        foreach ($requiredDirs as $dir) {
            if (is_dir($path . '/' . $dir)) {
                $foundRequired++;
            }
        }

        return $foundRequired >= 1;
    }

    /**
     * Find all problem directories in extracted path
     */
    protected function findProblemDirectories(string $basePath): array
    {
        $dirs = [];

        // Check if basePath itself is a problem
        if ($this->isBocaProblemDirectory($basePath)) {
            return [$basePath];
        }

        // Scan subdirectories
        $entries = scandir($basePath);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;

            $fullPath = $basePath . '/' . $entry;
            if (is_dir($fullPath) && $this->isBocaProblemDirectory($fullPath)) {
                $dirs[] = $fullPath;
            }
        }

        return $dirs;
    }

    /**
     * Import a single problem from BOCA directory structure
     */
    protected function importProblem(string $problemDir, ?int $contestId): ?array
    {
        $problemName = basename($problemDir);
        $code = strtoupper(Str::slug($problemName, '_'));

        // Check if already exists
        $exists = DB::table('problem_bank')->where('code', $code)->exists();
        if ($exists) {
            // Update instead of skip
            $code = $code . '_' . date('His');
        }

        // Read problem details
        $description = $this->readDescription($problemDir);
        $limits = $this->readLimits($problemDir);
        $testCases = $this->readTestCases($problemDir);

        // Parse description for input/output format
        $parsedDescription = $this->parseDescription($description);

        // Determine difficulty (heuristic based on test case count and limits)
        $difficulty = $this->determineDifficulty($testCases, $limits);

        // Insert into problem_bank
        $problemData = [
            'code' => $code,
            'name' => ucfirst(str_replace(['_', '-'], ' ', $problemName)),
            'description' => $parsedDescription['description'] ?? $description,
            'input_description' => $parsedDescription['input'] ?? 'Ver descricao do problema.',
            'output_description' => $parsedDescription['output'] ?? 'Ver descricao do problema.',
            'sample_input' => $testCases['sample_input'] ?? '',
            'sample_output' => $testCases['sample_output'] ?? '',
            'notes' => 'Importado do BOCA - ' . $problemName,
            'time_limit' => $limits['time'] ?? 1,
            'memory_limit' => $limits['memory'] ?? 256,
            'difficulty' => $difficulty,
            'tags' => json_encode(['boca', 'imported']),
            'source' => 'BOCA',
            'source_url' => 'https://github.com/cassiopc/boca',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            DB::table('problem_bank')->insert($problemData);
        } catch (\Exception $e) {
            throw new \Exception("Erro ao inserir problema: " . $e->getMessage());
        }

        // Store test cases for auto-judge
        $this->storeTestCases($code, $testCases, $problemDir);

        return [
            'code' => $code,
            'name' => $problemData['name'],
            'test_cases' => count($testCases['cases'] ?? []),
        ];
    }

    /**
     * Read problem description
     */
    protected function readDescription(string $problemDir): string
    {
        $descDir = $problemDir . '/description';
        if (!is_dir($descDir)) {
            return 'Descricao nao disponivel.';
        }

        // Look for description file (GLOB_BRACE not available on Alpine/musl)
        $files = array_merge(
            glob($descDir . '/*.txt') ?: [],
            glob($descDir . '/*.html') ?: [],
            glob($descDir . '/*.pdf') ?: [],
            glob($descDir . '/*.tex') ?: [],
            glob($descDir . '/*.md') ?: []
        );

        foreach ($files as $file) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            if (in_array($ext, ['txt', 'md'])) {
                return file_get_contents($file);
            }
            if ($ext === 'html') {
                $html = file_get_contents($file);
                return strip_tags($html);
            }
        }

        // Try problem.info file
        $infoFile = $descDir . '/problem.info';
        if (file_exists($infoFile)) {
            return file_get_contents($infoFile);
        }

        return 'Descricao nao disponivel.';
    }

    /**
     * Read problem limits
     */
    protected function readLimits(string $problemDir): array
    {
        $limitsDir = $problemDir . '/limits';
        $defaults = ['time' => 1, 'memory' => 256, 'output' => 1024];

        if (!is_dir($limitsDir)) {
            return $defaults;
        }

        // Try to read from various language limit files
        $limitFiles = ['c', 'cpp', 'java', 'python', 'py'];

        foreach ($limitFiles as $lang) {
            $file = $limitsDir . '/' . $lang;
            if (file_exists($file)) {
                $content = file_get_contents($file);

                // Parse shell script output format
                // Typical format: echo "4" (time) \n echo "10" (reps) \n echo "512" (mem) \n echo "1024" (maxfilesize)
                if (preg_match_all('/echo\s+"?(\d+)"?/', $content, $matches)) {
                    if (isset($matches[1][0])) $defaults['time'] = (int) $matches[1][0];
                    if (isset($matches[1][2])) $defaults['memory'] = (int) $matches[1][2];
                    if (isset($matches[1][3])) $defaults['output'] = (int) $matches[1][3];
                }
                break;
            }
        }

        return $defaults;
    }

    /**
     * Read test cases
     */
    protected function readTestCases(string $problemDir): array
    {
        $inputDir = $problemDir . '/input';
        $outputDir = $problemDir . '/output';
        $testsDir = $problemDir . '/tests';

        $cases = [];
        $sampleInput = '';
        $sampleOutput = '';

        // Method 1: input/output directories
        if (is_dir($inputDir) && is_dir($outputDir)) {
            $inputFiles = glob($inputDir . '/*');

            foreach ($inputFiles as $inputFile) {
                $basename = basename($inputFile);
                $outputFile = $outputDir . '/' . $basename;

                if (file_exists($outputFile)) {
                    $input = file_get_contents($inputFile);
                    $output = file_get_contents($outputFile);

                    $cases[] = [
                        'input' => $input,
                        'output' => $output,
                        'name' => $basename,
                    ];

                    // Use first case as sample
                    if (empty($sampleInput)) {
                        $sampleInput = $input;
                        $sampleOutput = $output;
                    }
                }
            }
        }

        // Method 2: tests directory with numbered files
        if (is_dir($testsDir)) {
            $testInputs = glob($testsDir . '/*.in') + glob($testsDir . '/input*');

            foreach ($testInputs as $inputFile) {
                $basename = basename($inputFile, '.in');
                $outputFile = $testsDir . '/' . $basename . '.out';

                if (!file_exists($outputFile)) {
                    $outputFile = $testsDir . '/output' . substr($basename, 5); // input01 -> output01
                }

                if (file_exists($outputFile)) {
                    $input = file_get_contents($inputFile);
                    $output = file_get_contents($outputFile);

                    $cases[] = [
                        'input' => $input,
                        'output' => $output,
                        'name' => $basename,
                    ];

                    if (empty($sampleInput)) {
                        $sampleInput = $input;
                        $sampleOutput = $output;
                    }
                }
            }
        }

        return [
            'cases' => $cases,
            'sample_input' => trim($sampleInput),
            'sample_output' => trim($sampleOutput),
        ];
    }

    /**
     * Parse description to extract input/output format
     */
    protected function parseDescription(string $description): array
    {
        $result = [
            'description' => $description,
            'input' => '',
            'output' => '',
        ];

        // Try to find Input section
        if (preg_match('/(?:entrada|input)[:\s]*\n?(.*?)(?=(?:sa[ií]da|output|$))/is', $description, $matches)) {
            $result['input'] = trim($matches[1]);
        }

        // Try to find Output section
        if (preg_match('/(?:sa[ií]da|output)[:\s]*\n?(.*?)(?=(?:exemplo|sample|$))/is', $description, $matches)) {
            $result['output'] = trim($matches[1]);
        }

        // Extract main description (before input/output)
        if (preg_match('/^(.*?)(?:entrada|input)/is', $description, $matches)) {
            $result['description'] = trim($matches[1]);
        }

        return $result;
    }

    /**
     * Determine problem difficulty based on test cases and limits
     */
    protected function determineDifficulty(array $testCases, array $limits): string
    {
        $caseCount = count($testCases['cases'] ?? []);
        $timeLimit = $limits['time'] ?? 1;

        // Simple heuristic
        if ($caseCount <= 5 && $timeLimit <= 1) {
            return 'easy';
        }
        if ($caseCount <= 15 && $timeLimit <= 3) {
            return 'medium';
        }
        return 'hard';
    }

    /**
     * Store test cases for auto-judge
     */
    protected function storeTestCases(string $problemCode, array $testCases, string $problemDir): void
    {
        $storagePath = storage_path('app/problems/' . $problemCode);

        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }

        // Store individual test cases
        foreach ($testCases['cases'] as $index => $case) {
            $num = str_pad($index + 1, 2, '0', STR_PAD_LEFT);
            file_put_contents($storagePath . "/input{$num}.txt", $case['input']);
            file_put_contents($storagePath . "/output{$num}.txt", $case['output']);
        }

        // Copy compare script if exists
        $compareDir = $problemDir . '/compare';
        if (is_dir($compareDir)) {
            $this->copyDirectory($compareDir, $storagePath . '/compare');
        }

        // Copy compile scripts if exists
        $compileDir = $problemDir . '/compile';
        if (is_dir($compileDir)) {
            $this->copyDirectory($compileDir, $storagePath . '/compile');
        }

        // Copy run scripts if exists
        $runDir = $problemDir . '/run';
        if (is_dir($runDir)) {
            $this->copyDirectory($runDir, $storagePath . '/run');
        }
    }

    /**
     * Copy directory recursively
     */
    protected function copyDirectory(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }

        $dir = opendir($src);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;

            $srcFile = $src . '/' . $file;
            $dstFile = $dst . '/' . $file;

            if (is_dir($srcFile)) {
                $this->copyDirectory($srcFile, $dstFile);
            } else {
                copy($srcFile, $dstFile);
            }
        }
        closedir($dir);
    }

    /**
     * Cleanup temporary directory
     */
    protected function cleanup(string $path): void
    {
        if (!is_dir($path)) return;

        $files = array_diff(scandir($path), ['.', '..']);
        foreach ($files as $file) {
            $fullPath = $path . '/' . $file;
            is_dir($fullPath) ? $this->cleanup($fullPath) : unlink($fullPath);
        }
        rmdir($path);
    }

    /**
     * Get import result
     */
    protected function getResult(): array
    {
        return [
            'success' => empty($this->errors),
            'imported' => $this->imported,
            'errors' => $this->errors,
            'count' => count($this->imported),
        ];
    }
}
