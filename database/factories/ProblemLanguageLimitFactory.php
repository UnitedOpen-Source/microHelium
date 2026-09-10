<?php

namespace Database\Factories;

use App\Models\Language;
use App\Models\Problem;
use App\Models\ProblemLanguageLimit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProblemLanguageLimit>
 */
class ProblemLanguageLimitFactory extends Factory
{
    protected $model = ProblemLanguageLimit::class;

    public function definition(): array
    {
        return [
            'problem_id' => Problem::factory(),
            'language_id' => Language::factory(),
            'time_limit' => null,
            'memory_limit' => null,
            'auto_judge_enabled' => null,
        ];
    }
}
