<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
    ];

    protected $casts = [
        'tags' => 'array',
        'is_active' => 'boolean',
    ];

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
        return match($this->difficulty) {
            'easy' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
            'medium' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
            'hard' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
            default => 'bg-gray-100 text-gray-800',
        };
    }

    public function getDifficultyLabelAttribute(): string
    {
        return match($this->difficulty) {
            'easy' => 'Facil',
            'medium' => 'Medio',
            'hard' => 'Dificil',
            default => 'Desconhecido',
        };
    }
}
