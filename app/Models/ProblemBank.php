<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'owning_org_id');
    }

    public function ownershipTransfers(): HasMany
    {
        return $this->hasMany(ProblemBankOwnershipTransfer::class);
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
