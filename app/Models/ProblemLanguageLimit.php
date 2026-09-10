<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProblemLanguageLimit extends Model
{
    use HasFactory;

    protected $fillable = [
        'problem_id',
        'language_id',
        'time_limit',
        'memory_limit',
        'auto_judge_enabled',
    ];

    protected $casts = [
        'auto_judge_enabled' => 'boolean',
    ];

    public function problem(): BelongsTo
    {
        return $this->belongsTo(Problem::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
