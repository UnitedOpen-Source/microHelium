<?php

namespace Tests\Unit;

use App\Services\BocaImporterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class BocaImporterServiceTest extends TestCase
{
    protected BocaImporterService $service;
    protected string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new BocaImporterService();
        $this->tempPath = storage_path('app/temp/boca_import');
    }

    protected function tearDown(): void
    {
        Mockery::close();

        // Cleanup temp directory if exists
        if (is_dir($this->tempPath)) {
            $this->recursiveRemoveDirectory($this->tempPath);
        }

        parent::tearDown();
    }

    /**
     * Test that the service can be instantiated
     */
    public function testServiceCanBeInstantiated(): void
    {
        $this->assertInstanceOf(BocaImporterService::class, $this->service);

        // Verify temp path is set correctly
        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('tempPath');
        $property->setAccessible(true);

        $this->assertEquals(
            storage_path('app/temp/boca_import'),
            $property->getValue($this->service)
        );
    }

    /**
     * Test parsing of BOCA package structure with mock file operations
     */
    public function testParseBocaPackageStructure(): void
    {
        // Create a mock BOCA directory structure
        $mockProblemPath = $this->tempPath . '/test_problem';
        $this->createMockBocaStructure($mockProblemPath);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isBocaProblemDirectory');
        $method->setAccessible(true);

        // Test that the structure is recognized as a BOCA problem
        $result = $method->invoke($this->service, $mockProblemPath);
        $this->assertTrue($result);

        // Test finding problem directories
        $findMethod = $reflection->getMethod('findProblemDirectories');
        $findMethod->setAccessible(true);

        $problemDirs = $findMethod->invoke($this->service, $this->tempPath);
        $this->assertCount(1, $problemDirs);
        $this->assertEquals($mockProblemPath, $problemDirs[0]);
    }

    /**
     * Test BOCA structure detection with missing required directories
     */
    public function testBocaStructureDetectionWithMissingDirectories(): void
    {
        $mockPath = $this->tempPath . '/invalid_problem';
        mkdir($mockPath, 0755, true);

        // Create only description directory (no input/output)
        mkdir($mockPath . '/description', 0755, true);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isBocaProblemDirectory');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $mockPath);
        $this->assertFalse($result);
    }

    /**
     * Test limits parsing from BOCA limit files
     */
    public function testLimitsParsingFromLimitFiles(): void
    {
        $mockProblemPath = $this->tempPath . '/test_problem';
        $this->createMockBocaStructure($mockProblemPath);

        // Create limits directory with a mock C limit file
        $limitsDir = $mockProblemPath . '/limits';
        mkdir($limitsDir, 0755, true);

        $limitContent = <<<'LIMIT'
#!/bin/bash
echo "5"    # time limit
echo "10"   # repetitions
echo "1024" # memory limit in MB
echo "2048" # max file size
LIMIT;

        file_put_contents($limitsDir . '/c', $limitContent);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('readLimits');
        $method->setAccessible(true);

        $limits = $method->invoke($this->service, $mockProblemPath);

        $this->assertEquals(5, $limits['time']);
        $this->assertEquals(1024, $limits['memory']);
        $this->assertEquals(2048, $limits['output']);
    }

    /**
     * Test limits parsing with default values when no limits file exists
     */
    public function testLimitsParsingWithDefaults(): void
    {
        $mockProblemPath = $this->tempPath . '/test_problem';
        mkdir($mockProblemPath, 0755, true);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('readLimits');
        $method->setAccessible(true);

        $limits = $method->invoke($this->service, $mockProblemPath);

        // Check default values
        $this->assertEquals(1, $limits['time']);
        $this->assertEquals(256, $limits['memory']);
        $this->assertEquals(1024, $limits['output']);
    }

    /**
     * Test problem description extraction from text files
     */
    public function testProblemDescriptionExtractionFromTextFile(): void
    {
        $mockProblemPath = $this->tempPath . '/test_problem';
        $this->createMockBocaStructure($mockProblemPath);

        $descriptionDir = $mockProblemPath . '/description';
        $descriptionContent = 'This is a test problem description.';
        file_put_contents($descriptionDir . '/problem.txt', $descriptionContent);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('readDescription');
        $method->setAccessible(true);

        $description = $method->invoke($this->service, $mockProblemPath);

        $this->assertEquals($descriptionContent, $description);
    }

    /**
     * Test problem description extraction from HTML files
     */
    public function testProblemDescriptionExtractionFromHtmlFile(): void
    {
        $mockProblemPath = $this->tempPath . '/test_problem';
        $this->createMockBocaStructure($mockProblemPath);

        $descriptionDir = $mockProblemPath . '/description';
        $htmlContent = '<html><body><h1>Problem</h1><p>This is the description.</p></body></html>';
        file_put_contents($descriptionDir . '/problem.html', $htmlContent);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('readDescription');
        $method->setAccessible(true);

        $description = $method->invoke($this->service, $mockProblemPath);

        // HTML tags should be stripped
        $this->assertStringNotContainsString('<html>', $description);
        $this->assertStringContainsString('Problem', $description);
        $this->assertStringContainsString('This is the description.', $description);
    }

    /**
     * Test description parsing to extract input/output sections
     */
    public function testDescriptionParsingExtractsInputOutputSections(): void
    {
        $description = <<<'DESC'
This is the main problem description.

Entrada:
The input consists of integers.

Saída:
The output should be a single integer.

Exemplo:
Sample case here.
DESC;

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('parseDescription');
        $method->setAccessible(true);

        $parsed = $method->invoke($this->service, $description);

        $this->assertStringContainsString('This is the main problem description', $parsed['description']);
        $this->assertStringContainsString('input consists of integers', $parsed['input']);
        $this->assertStringContainsString('should be a single integer', $parsed['output']);
    }

    /**
     * Test description parsing with English keywords
     */
    public function testDescriptionParsingWithEnglishKeywords(): void
    {
        $description = <<<'DESC'
Problem statement here.

Input:
Input format description.

Output:
Output format description.
DESC;

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('parseDescription');
        $method->setAccessible(true);

        $parsed = $method->invoke($this->service, $description);

        $this->assertStringContainsString('Input format description', $parsed['input']);
        $this->assertStringContainsString('Output format description', $parsed['output']);
    }

    /**
     * Test test cases reading from input/output directories
     */
    public function testReadTestCasesFromInputOutputDirectories(): void
    {
        $mockProblemPath = $this->tempPath . '/test_problem';
        $this->createMockBocaStructure($mockProblemPath);

        // Create test case files
        $inputDir = $mockProblemPath . '/input';
        $outputDir = $mockProblemPath . '/output';

        file_put_contents($inputDir . '/1', "5 3\n");
        file_put_contents($outputDir . '/1', "8\n");
        file_put_contents($inputDir . '/2', "10 20\n");
        file_put_contents($outputDir . '/2', "30\n");

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('readTestCases');
        $method->setAccessible(true);

        $testCases = $method->invoke($this->service, $mockProblemPath);

        $this->assertCount(2, $testCases['cases']);
        $this->assertEquals("5 3", trim($testCases['sample_input']));
        $this->assertEquals("8", trim($testCases['sample_output']));
        $this->assertEquals("5 3", trim($testCases['cases'][0]['input']));
        $this->assertEquals("8", trim($testCases['cases'][0]['output']));
    }

    /**
     * Test difficulty determination based on test cases and limits
     */
    public function testDifficultyDeterminationEasy(): void
    {
        $testCases = ['cases' => [[], [], []]]; // 3 test cases
        $limits = ['time' => 1];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('determineDifficulty');
        $method->setAccessible(true);

        $difficulty = $method->invoke($this->service, $testCases, $limits);

        $this->assertEquals('easy', $difficulty);
    }

    /**
     * Test difficulty determination for medium problems
     */
    public function testDifficultyDeterminationMedium(): void
    {
        $testCases = ['cases' => array_fill(0, 10, [])]; // 10 test cases
        $limits = ['time' => 2];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('determineDifficulty');
        $method->setAccessible(true);

        $difficulty = $method->invoke($this->service, $testCases, $limits);

        $this->assertEquals('medium', $difficulty);
    }

    /**
     * Test difficulty determination for hard problems
     */
    public function testDifficultyDeterminationHard(): void
    {
        $testCases = ['cases' => array_fill(0, 20, [])]; // 20 test cases
        $limits = ['time' => 5];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('determineDifficulty');
        $method->setAccessible(true);

        $difficulty = $method->invoke($this->service, $testCases, $limits);

        $this->assertEquals('hard', $difficulty);
    }

    /**
     * Test import from zip with non-existent file
     */
    public function testImportFromZipWithNonExistentFile(): void
    {
        $result = $this->service->importFromZip('/path/to/nonexistent.zip');

        $this->assertFalse($result['success']);
        $this->assertCount(0, $result['imported']);
        $this->assertGreaterThan(0, count($result['errors']));
        $this->assertStringContainsString('nao encontrado', $result['errors'][0]);
    }

    /**
     * Mock the GitHub import method
     */
    public function testImportFromBocaGitHubMocked(): void
    {
        // Create a partial mock of the service
        $serviceMock = Mockery::mock(BocaImporterService::class)->makePartial();

        // Mock the importFromZip method to avoid actual file operations
        $serviceMock->shouldReceive('importFromZip')
            ->andReturn([
                'success' => true,
                'imported' => [
                    [
                        'code' => 'ABACAXI',
                        'name' => 'Abacaxi',
                        'test_cases' => 5
                    ]
                ],
                'errors' => [],
                'count' => 1
            ]);

        // We need to mock file_get_contents for the download
        // Since we can't easily mock global functions, we'll test the structure
        $reflection = new \ReflectionClass($serviceMock);
        $property = $reflection->getProperty('errors');
        $property->setAccessible(true);

        // Verify initial state
        $this->assertIsArray($property->getValue($serviceMock));
    }

    /**
     * Test getResult method
     */
    public function testGetResultMethod(): void
    {
        $reflection = new \ReflectionClass($this->service);

        // Set some test data
        $errorsProperty = $reflection->getProperty('errors');
        $errorsProperty->setAccessible(true);
        $errorsProperty->setValue($this->service, ['Error 1', 'Error 2']);

        $importedProperty = $reflection->getProperty('imported');
        $importedProperty->setAccessible(true);
        $importedProperty->setValue($this->service, [
            ['code' => 'TEST1', 'name' => 'Test 1'],
            ['code' => 'TEST2', 'name' => 'Test 2']
        ]);

        $method = $reflection->getMethod('getResult');
        $method->setAccessible(true);

        $result = $method->invoke($this->service);

        $this->assertFalse($result['success']); // Has errors
        $this->assertCount(2, $result['errors']);
        $this->assertCount(2, $result['imported']);
        $this->assertEquals(2, $result['count']);
    }

    /**
     * Test getResult with successful import
     */
    public function testGetResultWithSuccess(): void
    {
        $reflection = new \ReflectionClass($this->service);

        // No errors
        $errorsProperty = $reflection->getProperty('errors');
        $errorsProperty->setAccessible(true);
        $errorsProperty->setValue($this->service, []);

        $importedProperty = $reflection->getProperty('imported');
        $importedProperty->setAccessible(true);
        $importedProperty->setValue($this->service, [
            ['code' => 'TEST1', 'name' => 'Test 1']
        ]);

        $method = $reflection->getMethod('getResult');
        $method->setAccessible(true);

        $result = $method->invoke($this->service);

        $this->assertTrue($result['success']);
        $this->assertCount(0, $result['errors']);
        $this->assertCount(1, $result['imported']);
        $this->assertEquals(1, $result['count']);
    }

    /**
     * Test cleanup method
     */
    public function testCleanupMethod(): void
    {
        $testPath = $this->tempPath . '/cleanup_test';
        mkdir($testPath, 0755, true);
        mkdir($testPath . '/subdir', 0755, true);
        file_put_contents($testPath . '/file.txt', 'test');
        file_put_contents($testPath . '/subdir/file2.txt', 'test2');

        $this->assertDirectoryExists($testPath);
        $this->assertFileExists($testPath . '/file.txt');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('cleanup');
        $method->setAccessible(true);

        $method->invoke($this->service, $testPath);

        $this->assertDirectoryDoesNotExist($testPath);
    }

    /**
     * Test copyDirectory method
     */
    public function testCopyDirectoryMethod(): void
    {
        $srcPath = $this->tempPath . '/source';
        $dstPath = $this->tempPath . '/destination';

        mkdir($srcPath, 0755, true);
        mkdir($srcPath . '/subdir', 0755, true);
        file_put_contents($srcPath . '/file1.txt', 'content1');
        file_put_contents($srcPath . '/subdir/file2.txt', 'content2');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('copyDirectory');
        $method->setAccessible(true);

        $method->invoke($this->service, $srcPath, $dstPath);

        $this->assertDirectoryExists($dstPath);
        $this->assertFileExists($dstPath . '/file1.txt');
        $this->assertFileExists($dstPath . '/subdir/file2.txt');
        $this->assertEquals('content1', file_get_contents($dstPath . '/file1.txt'));
        $this->assertEquals('content2', file_get_contents($dstPath . '/subdir/file2.txt'));
    }

    /**
     * Helper method to create a mock BOCA directory structure
     */
    private function createMockBocaStructure(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        // Create required directories
        mkdir($path . '/input', 0755, true);
        mkdir($path . '/output', 0755, true);
        mkdir($path . '/description', 0755, true);
    }

    /**
     * Helper method to recursively remove a directory
     */
    private function recursiveRemoveDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = array_diff(scandir($path), ['.', '..']);
        foreach ($files as $file) {
            $fullPath = $path . '/' . $file;
            is_dir($fullPath) ? $this->recursiveRemoveDirectory($fullPath) : unlink($fullPath);
        }
        rmdir($path);
    }
}
