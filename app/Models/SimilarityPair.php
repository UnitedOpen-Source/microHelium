<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SimilarityPair extends Model
{
    protected $fillable = [
        'similarity_check_id',
        'run_id_a',
        'run_id_b',
        'similarity_score',
    ];

    protected $casts = [
        'similarity_score' => 'float',
    ];

    public function check(): BelongsTo
    {
        return $this->belongsTo(SimilarityCheck::class, 'similarity_check_id');
    }

    public function runA(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id_a');
    }

    public function runB(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id_b');
    }
}
