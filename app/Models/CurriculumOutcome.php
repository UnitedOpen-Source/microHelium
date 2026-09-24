<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Issue #396 -- uma habilidade de um currículo oficial (ex. `EF06CO02`).
 * docs/specs/396-curriculos-oficiais.md.
 *
 * @property int $id
 * @property int $curriculum_framework_id
 * @property string $code
 * @property string|null $stage
 * @property string|null $axis
 * @property string $text
 * @property int $position
 */
class CurriculumOutcome extends Model
{
    use HasFactory;

    protected $fillable = [
        'curriculum_framework_id',
        'code',
        'stage',
        'axis',
        'text',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    /** @return BelongsTo<CurriculumFramework, $this> */
    public function framework(): BelongsTo
    {
        return $this->belongsTo(CurriculumFramework::class, 'curriculum_framework_id');
    }

    /** @return BelongsToMany<ProblemBank, $this> */
    public function problemBanks(): BelongsToMany
    {
        return $this->belongsToMany(ProblemBank::class, 'problem_bank_outcomes', 'curriculum_outcome_id', 'problem_bank_id')
            ->withTimestamps();
    }

    /**
     * A forma que a API devolve, em todo lugar que mostra uma habilidade.
     *
     * @return array{id: int, code: string, stage: string|null, axis: string|null, text: string, framework: array{slug: string, name: string}}
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'stage' => $this->stage,
            'axis' => $this->axis,
            'text' => $this->text,
            'framework' => [
                'slug' => $this->framework->slug,
                'name' => $this->framework->name,
            ],
        ];
    }
}
