<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProblemBank extends Model
{
    use HasFactory;

    protected $table = 'problem_bank';

    protected $fillable = [
        'code',
        'name',
        'description',
        'input_description',
        'output_description',
        'sample_input',
        'sample_output',
        'notes',
        'time_limit',
        'memory_limit',
        'source',
        'source_url',
        'difficulty',
        'tags',
        'is_active',
        'owning_org_id',
        'version',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_active' => 'boolean',
        'version' => 'integer',
    ];

    /**
     * Issue #46: nullable owning organization. Null means "legacy" --
     * administrable only by admin (docs/specs/46-bank-ownership.md).
     */
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'owning_org_id');
    }

    /** @return HasMany<ProblemBankOwnershipTransfer, $this> */
    public function ownershipTransfers(): HasMany
    {
        return $this->hasMany(ProblemBankOwnershipTransfer::class);
    }

    /**
     * Issue #43 -- every practice publication this entry has ever had,
     * open or closed (docs/specs/43-practice.md).
     */
    /** @return HasMany<PracticePublication, $this> */
    public function practicePublications(): HasMany
    {
        return $this->hasMany(PracticePublication::class);
    }

    /**
     * Issue #396 -- habilidades de currículos oficiais que este problema
     * exercita (docs/specs/396-curriculos-oficiais.md). N:N porque o mesmo
     * problema pode ser `EF06CO02` na BNCC e um item de KS3 ao mesmo tempo.
     */
    /** @return BelongsToMany<CurriculumOutcome, $this> */
    public function outcomes(): BelongsToMany
    {
        return $this->belongsToMany(CurriculumOutcome::class, 'problem_bank_outcomes', 'problem_bank_id', 'curriculum_outcome_id')
            ->withTimestamps()
            ->orderBy('curriculum_outcomes.curriculum_framework_id')
            ->orderBy('curriculum_outcomes.position');
    }

    public function activePracticePublication(): ?PracticePublication
    {
        return $this->practicePublications()
            ->whereNull('unpublished_at')
            ->latest('published_at')
            ->first();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByDifficulty($query, $difficulty)
    {
        return $query->where('difficulty', $difficulty);
    }

    public function getDifficultyBadgeAttribute(): string
    {
        return match ($this->difficulty) {
            'easy' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
            'medium' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
            'hard' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
            default => 'bg-gray-100 text-gray-800',
        };
    }

    public function getDifficultyLabelAttribute(): string
    {
        return match ($this->difficulty) {
            'easy' => 'Facil',
            'medium' => 'Medio',
            'hard' => 'Dificil',
            default => 'Desconhecido',
        };
    }
}
