<?php

namespace Database\Factories;

use App\Models\CurriculumFramework;
use App\Models\CurriculumOutcome;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurriculumOutcome>
 */
class CurriculumOutcomeFactory extends Factory
{
    protected $model = CurriculumOutcome::class;

    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = ++self::$sequence;

        return [
            'curriculum_framework_id' => CurriculumFramework::factory(),
            // Código fictício de propósito: nada que se confunda com um código oficial.
            'code' => 'TEST'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'stage' => '6º ano',
            'axis' => 'Pensamento Computacional',
            'text' => 'Habilidade de teste '.$n.'.',
            'position' => $n,
        ];
    }
}
