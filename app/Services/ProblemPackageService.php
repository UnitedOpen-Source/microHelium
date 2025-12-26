<?php

namespace App\Services;

use App\Models\Contest;
use App\Models\Problem;
use App\Models\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use ZipArchive;
use Illuminate\Support\Facades\Log;

class ProblemPackageService
{
    protected string $extractPath;

    public function __construct()
    {
        $this->extractPath = storage_path('app/problems');
    }

    public function importFromZip(Contest $contest, UploadedFile $file, array $overrides = []): Problem
    {
        $tempPath = $file->store('temp');
        $fullTempPath = storage_path("app/{$tempPath}");

        try {
            $zip = new ZipArchive();
            if ($zip->open($fullTempPath) !== true) {
                throw new \Exception('Failed to open ZIP file');
            }

            // Extract to temp directory
            $tempExtractDir = storage_path('app/temp/extract_' . uniqid());
            mkdir($tempExtractDir, 0755, true);
            $zip->extractTo($tempExtractDir);
            $zip->close();

            // Parse problem.info
            $problemInfo = $this->parseProblemInfo($tempExtractDir);

            // Merge with overrides
            $problemData = array_merge($problemInfo, $overrides);

            // Create problem
            $problem = Problem::create([
                'contest_id' => $contest->id,
                'short_name' => $problemData['short_name'] ?? $this->generateShortName($contest),
                'name' => $problemData['fullname'] ?? $problemData['name'] ?? 'Unnamed Problem',
                'basename' => $problemData['basename'],
                'description_file' => $problemData['descfile'] ?? null,
                'color_name' => $problemData['color_name'] ?? null,
                'color_hex' => $problemData['color_hex'] ?? null,
                'time_limit' => $problemData['time_limit'] ?? 1,
                'memory_limit' => $problemData['memory_limit'] ?? 256,
                'output_limit' => $problemData['output_limit'] ?? 1024,
                'auto_judge' => $problemData['auto_judge'] ?? true,
                'sort_order' => Problem::where('contest_id', $contest->id)->max('sort_order') + 1,
            ]);

            // Move files to final location
            $finalPath = "{$this->extractPath}/{$contest->id}/{$problem->basename}";
            $this->moveDirectory($tempExtractDir, $finalPath);

            // Import test cases
            $this->importTestCases($problem, $finalPath);

            // Cleanup
            Storage::delete($tempPath);

            return $problem;
        } catch (\Exception $e) {
            Storage::delete($tempPath);
            throw $e;
        }
    }

    protected function parseProblemInfo(string $extractDir): array
    {
        $infoFile = $this->findFile($extractDir, 'problem.info');
        if (!$infoFile) {
            throw new \Exception('problem.info file not found in package');
        }

        $info = [];
        $content = file_get_contents($infoFile);
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $info[trim($key)] = trim($value);
            }
        }

        if (empty($info['basename'])) {
            throw new \Exception('basename is required in problem.info');
        }

        return $info;
    }

    protected function findFile(string $dir, string $filename): ?string
    {
        // Check in root
        if (file_exists("{$dir}/{$filename}")) {
            return "{$dir}/{$filename}";
        }

        // Check in description subdirectory
        if (file_exists("{$dir}/description/{$filename}")) {
            return "{$dir}/description/{$filename}";
        }

        // Check in subdirectories (for nested zips)
        $subdirs = glob("{$dir}/*", GLOB_ONLYDIR);
        foreach ($subdirs as $subdir) {
            $result = $this->findFile($subdir, $filename);
            if ($result) {
                return $result;
            }
        }

        return null;
    }

    public function importTestCases(Problem $problem, string $packagePath): void
    {
        $inputDir = "{$packagePath}/input";
        $outputDir = "{$packagePath}/output";

        Log::info("Importing test cases from: {$packagePath}");
        Log::info("Input dir: {$inputDir}");
        Log::info("Output dir: {$outputDir}");


        if (!is_dir($inputDir) || !is_dir($outputDir)) {
            Log::warning("Input or output directory not found.");
            return;
        }

        $inputFiles = array_diff(scandir($inputDir), ['.', '..']);
        $number = 1;

        Log::info("Input files found: " . print_r($inputFiles, true));

        foreach ($inputFiles as $inputFilename) {
            $inputPath = "{$inputDir}/{$inputFilename}";
            if (!is_file($inputPath)) {
                Log::info("Skipping non-file: {$inputPath}");
                continue;
            }

            // Find corresponding output file
            $outputPath = "{$outputDir}/{$inputFilename}";
            if (!file_exists($outputPath)) {
                Log::info("Output file not found for: {$inputFilename}");
                continue;
            }

            $relativePath = "problems/{$problem->contest_id}/{$problem->basename}";

            TestCase::create([
                'problem_id' => $problem->id,
                'number' => $number,
                'input_file' => "{$relativePath}/input/{$inputFilename}",
                'output_file' => "{$relativePath}/output/{$inputFilename}",
                'input_hash' => hash_file('sha256', $inputPath),
                'output_hash' => hash_file('sha256', $outputPath),
                'is_sample' => $number <= 2, // First two test cases are samples
            ]);
            Log::info("Created test case #{$number}");

            $number++;
        }
    }

    protected function moveDirectory(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $files = array_diff(scandir($source), ['.', '..']);

        foreach ($files as $file) {
            $sourcePath = "{$source}/{$file}";
            $destPath = "{$dest}/{$file}";

            if (is_dir($sourcePath)) {
                $this->moveDirectory($sourcePath, $destPath);
            } else {
                rename($sourcePath, $destPath);
            }
        }

        rmdir($source);
    }

    protected function generateShortName(Contest $contest): string
    {
        $count = Problem::where('contest_id', $contest->id)->count();
        return chr(65 + $count); // A, B, C, ...
    }

    public function exportToZip(Problem $problem): string
    {
        $packagePath = $problem->getPackagePath();
        $zipPath = "exports/{$problem->basename}.zip";
        $fullZipPath = storage_path("app/{$zipPath}");

        if (!is_dir(dirname($fullZipPath))) {
            mkdir(dirname($fullZipPath), 0755, true);
        }

        $zip = new ZipArchive();
        $zip->open($fullZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $this->addDirectoryToZip($zip, $packagePath, $problem->basename);

        $zip->close();

        return $zipPath;
    }

    protected function addDirectoryToZip(ZipArchive $zip, string $dir, string $base): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = "{$dir}/{$file}";
            $zipPath = "{$base}/{$file}";

            if (is_dir($path)) {
                $zip->addEmptyDir($zipPath);
                $this->addDirectoryToZip($zip, $path, $zipPath);
            } else {
                $zip->addFile($path, $zipPath);
            }
        }
    }
}
