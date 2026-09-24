<?php

namespace Database\Factories;

use App\Models\CurriculumFramework;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurriculumFramework>
 */
class CurriculumFrameworkFactory extends Factory
{
    protected $model = CurriculumFramework::class;

    /** Sequência e não sorteio, pelo mesmo motivo de ProblemBankFactory: `slug` é unique. */
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = ++self::$sequence;

        return [
            'slug' => 'curriculo-'.$n,
            'name' => 'Currículo de teste '.$n,
            'jurisdiction' => 'BR',
            'version' => '2022',
            'locale' => 'pt_BR',
            'source_url' => 'https://example.org/curriculo-'.$n.'.pdf',
            'source_consulted_at' => '2026-09-24',
        ];
    }
}
