<?php

namespace Tests\Unit;

use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\ProblemLanguageLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProblemLanguageLimitTest extends TestCase
{
    use RefreshDatabase;

    private function makeProblemAndLanguages(array $problemOverrides = []): array
    {
        $contest = Contest::factory()->create();
        $problem = Problem::factory()->create($problemOverrides + [
            'contest_id' => $contest->id,
            'time_limit' => 1,
            'memory_limit' => 256,
            'auto_judge' => true,
        ]);
        // LanguageFactory picks `name` from a small fixed list without
        // uniqueness, so pin it explicitly to avoid an occasional
        // (contest_id, name) unique-constraint collision between the two.
        $languageWithOverride = Language::factory()->create(['contest_id' => $contest->id, 'name' => 'C++']);
        $languageWithoutOverride = Language::factory()->create(['contest_id' => $contest->id, 'name' => 'Python']);

        return [$problem, $languageWithOverride, $languageWithoutOverride];
    }

    public function test_limits_fall_back_to_problem_defaults_when_no_override_exists()
    {
        [$problem, , $language] = $this->makeProblemAndLanguages();

        $this->assertSame(1, $problem->getTimeLimitFor($language));
        $this->assertSame(256, $problem->getMemoryLimitFor($language));
        $this->assertTrue($problem->isAutoJudgeEnabledFor($language));
    }

    public function test_per_language_override_takes_precedence_over_problem_defaults()
    {
        [$problem, $language, $otherLanguage] = $this->makeProblemAndLanguages();

        ProblemLanguageLimit::create([
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'time_limit' => 10,
            'memory_limit' => 1024,
            'auto_judge_enabled' => false,
        ]);

        // Overridden language gets the override...
        $this->assertSame(10, $problem->getTimeLimitFor($language));
        $this->assertSame(1024, $problem->getMemoryLimitFor($language));
        $this->assertFalse($problem->isAutoJudgeEnabledFor($language));

        // ...while a language with no row still gets the problem defaults.
        $this->assertSame(1, $problem->getTimeLimitFor($otherLanguage));
        $this->assertSame(256, $problem->getMemoryLimitFor($otherLanguage));
        $this->assertTrue($problem->isAutoJudgeEnabledFor($otherLanguage));
    }

    public function test_partial_override_only_replaces_the_fields_that_are_set()
    {
        [$problem, $language] = $this->makeProblemAndLanguages();

        // Only overriding the time limit -- memory_limit and auto_judge_enabled
        // stay null, meaning "inherit from the problem".
        ProblemLanguageLimit::create([
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'time_limit' => 5,
        ]);

        $this->assertSame(5, $problem->getTimeLimitFor($language));
        $this->assertSame(256, $problem->getMemoryLimitFor($language));
        $this->assertTrue($problem->isAutoJudgeEnabledFor($language));
    }
}
