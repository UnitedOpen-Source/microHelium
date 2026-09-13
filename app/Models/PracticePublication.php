<?php

namespace App\Models;

use Helium\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #43 -- a versioned publication of a ProblemBank entry into the
 * practice library (docs/specs/43-practice.md).
 *
 * "Active" means published and not yet withdrawn. A bank entry has at most
 * one active publication at a time; earlier ones stay as closed rows so the
 * runs judged against them keep pointing at the material they actually saw.
 */
class PracticePublication extends Model
{
    protected $fillable = [
        'problem_bank_id',
        'problem_id',
        'version',
        'published_at',
        'unpublished_at',
        'published_by',
        'source_snapshot_hash',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'unpublished_at' => 'datetime',
    ];

    public function problemBank(): BelongsTo
    {
        return $this->belongsTo(ProblemBank::class);
    }

    public function problem(): BelongsTo
    {
        return $this->belongsTo(Problem::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by', 'user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('unpublished_at');
    }

    public function isActive(): bool
    {
        return $this->unpublished_at === null;
    }
}
