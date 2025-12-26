<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Language;
use App\Models\Contest;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LanguageModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that getDefaultLanguages() returns an array
     *
     * @return void
     */
    public function testGetDefaultLanguagesReturnsArray()
    {
        $languages = Language::getDefaultLanguages();

        $this->assertIsArray($languages);
        $this->assertNotEmpty($languages);
    }

    /**
     * Test that each language has all required keys
     *
     * @return void
     */
    public function testEachLanguageHasRequiredKeys()
    {
        $languages = Language::getDefaultLanguages();
        $requiredKeys = ['name', 'extension', 'file_ext', 'compile_command', 'run_command', 'is_active', 'category'];

        foreach ($languages as $index => $language) {
            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $language,
                    "Language at index {$index} ('{$language['name']}') is missing required key: {$key}"
                );
            }
        }
    }

    /**
     * Test that all extensions are unique
     *
     * @return void
     */
    public function testAllExtensionsAreUnique()
    {
        $languages = Language::getDefaultLanguages();
        $extensions = array_column($languages, 'extension');
        $uniqueExtensions = array_unique($extensions);

        $this->assertEquals(
            count($extensions),
            count($uniqueExtensions),
            'Some language extensions are not unique'
        );
    }

    /**
     * Test that extensions are unique by checking for duplicates
     *
     * @return void
     */
    public function testNoDuplicateExtensions()
    {
        $languages = Language::getDefaultLanguages();
        $extensions = array_column($languages, 'extension');
        $extensionCounts = array_count_values($extensions);
        $duplicates = array_filter($extensionCounts, function ($count) {
            return $count > 1;
        });

        $this->assertEmpty(
            $duplicates,
            'Found duplicate extensions: ' . implode(', ', array_keys($duplicates))
        );
    }

    /**
     * Test that getFileExtension() returns correct file extension
     *
     * @return void
     */
    public function testGetFileExtensionReturnsCorrectExtension()
    {
        $contest = Contest::factory()->create();

        // Test with C language
        $language = Language::create([
            'contest_id' => $contest->id,
            'name' => 'C (GCC 13)',
            'extension' => 'c_gcc13',
            'compile_command' => 'gcc -o {output} {source}',
            'run_command' => './{executable}',
            'is_active' => true,
        ]);

        $this->assertEquals('c', $language->getFileExtension());
    }

    /**
     * Test getFileExtension() with multiple languages
     *
     * @return void
     */
    public function testGetFileExtensionWithMultipleLanguages()
    {
        $contest = Contest::factory()->create();

        $testCases = [
            ['extension' => 'cpp_gpp13', 'expected' => 'cpp'],
            ['extension' => 'py3', 'expected' => 'py'],
            ['extension' => 'java21', 'expected' => 'java'],
            ['extension' => 'js_node24', 'expected' => 'js'],
            ['extension' => 'rs', 'expected' => 'rs'],
        ];

        foreach ($testCases as $testCase) {
            $language = Language::create([
                'contest_id' => $contest->id,
                'name' => "Test Language {$testCase['extension']}",
                'extension' => $testCase['extension'],
                'compile_command' => 'test',
                'run_command' => 'test',
                'is_active' => true,
            ]);

            $this->assertEquals(
                $testCase['expected'],
                $language->getFileExtension(),
                "Failed for extension: {$testCase['extension']}"
            );
        }
    }

    /**
     * Test getFileExtension() falls back to extension when not in defaults
     *
     * @return void
     */
    public function testGetFileExtensionFallbackToExtension()
    {
        $contest = Contest::factory()->create();

        $language = Language::create([
            'contest_id' => $contest->id,
            'name' => 'Custom Language',
            'extension' => 'custom_ext',
            'compile_command' => 'test',
            'run_command' => 'test',
            'is_active' => true,
        ]);

        $this->assertEquals('custom_ext', $language->getFileExtension());
    }

    /**
     * Test Language belongsTo Contest relationship
     *
     * @return void
     */
    public function testLanguageBelongsToContest()
    {
        $contest = Contest::factory()->create();
        $language = Language::create([
            'contest_id' => $contest->id,
            'name' => 'Test Language',
            'extension' => 'test_ext',
            'compile_command' => 'test compile',
            'run_command' => 'test run',
            'is_active' => true,
        ]);

        $this->assertInstanceOf(Contest::class, $language->contest);
        $this->assertEquals($contest->id, $language->contest->id);
    }

    /**
     * Test Language hasMany Runs relationship
     *
     * @return void
     */
    public function testLanguageHasManyRuns()
    {
        $contest = Contest::factory()->create();
        $language = Language::create([
            'contest_id' => $contest->id,
            'name' => 'Test Language',
            'extension' => 'test_ext',
            'compile_command' => 'test compile',
            'run_command' => 'test run',
            'is_active' => true,
        ]);

        // Create runs associated with this language
        $run1 = Run::factory()->create([
            'language_id' => $language->id,
            'contest_id' => $contest->id,
        ]);
        $run2 = Run::factory()->create([
            'language_id' => $language->id,
            'contest_id' => $contest->id,
        ]);

        $this->assertCount(2, $language->runs);
        $this->assertTrue($language->runs->contains($run1));
        $this->assertTrue($language->runs->contains($run2));
    }

    /**
     * Test that each language has valid category
     *
     * @return void
     */
    public function testEachLanguageHasValidCategory()
    {
        $languages = Language::getDefaultLanguages();
        $validCategories = ['compiled', 'interpreted'];

        foreach ($languages as $language) {
            $this->assertContains(
                $language['category'],
                $validCategories,
                "Language '{$language['name']}' has invalid category: {$language['category']}"
            );
        }
    }

    /**
     * Test that is_active is boolean
     *
     * @return void
     */
    public function testIsActiveIsBooleanInDefaultLanguages()
    {
        $languages = Language::getDefaultLanguages();

        foreach ($languages as $language) {
            $this->assertIsBool(
                $language['is_active'],
                "Language '{$language['name']}' has non-boolean is_active value"
            );
        }
    }

    /**
     * Test that all language names are non-empty strings
     *
     * @return void
     */
    public function testAllLanguageNamesAreNonEmptyStrings()
    {
        $languages = Language::getDefaultLanguages();

        foreach ($languages as $language) {
            $this->assertIsString($language['name']);
            $this->assertNotEmpty($language['name']);
        }
    }

    /**
     * Test that compile_command and run_command are non-empty strings
     *
     * @return void
     */
    public function testCompileAndRunCommandsAreNonEmptyStrings()
    {
        $languages = Language::getDefaultLanguages();

        foreach ($languages as $language) {
            $this->assertIsString($language['compile_command']);
            $this->assertNotEmpty($language['compile_command']);

            $this->assertIsString($language['run_command']);
            $this->assertNotEmpty($language['run_command']);
        }
    }

    /**
     * Test that file_ext values are non-empty strings
     *
     * @return void
     */
    public function testFileExtensionsAreNonEmptyStrings()
    {
        $languages = Language::getDefaultLanguages();

        foreach ($languages as $language) {
            $this->assertIsString($language['file_ext']);
            $this->assertNotEmpty($language['file_ext']);
        }
    }

    /**
     * Test Language model fillable attributes
     *
     * @return void
     */
    public function testLanguageModelFillableAttributes()
    {
        $contest = Contest::factory()->create();

        $data = [
            'contest_id' => $contest->id,
            'name' => 'Test Language',
            'extension' => 'test',
            'compile_command' => 'compile test',
            'run_command' => 'run test',
            'is_active' => true,
        ];

        $language = Language::create($data);

        $this->assertEquals($data['name'], $language->name);
        $this->assertEquals($data['extension'], $language->extension);
        $this->assertEquals($data['compile_command'], $language->compile_command);
        $this->assertEquals($data['run_command'], $language->run_command);
        $this->assertTrue($language->is_active);
    }

    /**
     * Test that is_active is cast to boolean
     *
     * @return void
     */
    public function testIsActiveCastToBoolean()
    {
        $contest = Contest::factory()->create();

        $language = Language::create([
            'contest_id' => $contest->id,
            'name' => 'Test Language',
            'extension' => 'test',
            'compile_command' => 'test',
            'run_command' => 'test',
            'is_active' => 1,
        ]);

        $this->assertIsBool($language->is_active);
        $this->assertTrue($language->is_active);
    }

    /**
     * Test that soft deletes trait is used
     *
     * @return void
     */
    public function testLanguageUsesSoftDeletes()
    {
        $contest = Contest::factory()->create();

        $language = Language::create([
            'contest_id' => $contest->id,
            'name' => 'Test Language',
            'extension' => 'test',
            'compile_command' => 'test',
            'run_command' => 'test',
            'is_active' => true,
        ]);

        $languageId = $language->id;
        $language->delete();

        // Should not find with regular query
        $this->assertNull(Language::find($languageId));

        // Should find with trashed
        $this->assertNotNull(Language::withTrashed()->find($languageId));
    }

    /**
     * Test getAllLanguages() method
     *
     * @return void
     */
    public function testGetAllLanguagesReturnsArrayWithIds()
    {
        $languages = Language::getAllLanguages();

        $this->assertIsArray($languages);
        $this->assertNotEmpty($languages);

        foreach ($languages as $index => $language) {
            $this->assertArrayHasKey('id', $language);
            $this->assertEquals($index + 1, $language['id']);
        }
    }

    /**
     * Test that default languages cover common programming languages
     *
     * @return void
     */
    public function testDefaultLanguagesIncludeCommonLanguages()
    {
        $languages = Language::getDefaultLanguages();
        $languageNames = array_column($languages, 'name');
        $languageNamesString = implode(' ', $languageNames);

        // Check for common languages
        $this->assertStringContainsString('C', $languageNamesString);
        $this->assertStringContainsString('C++', $languageNamesString);
        $this->assertStringContainsString('Java', $languageNamesString);
        $this->assertStringContainsString('Python', $languageNamesString);
        $this->assertStringContainsString('JavaScript', $languageNamesString);
    }
}
