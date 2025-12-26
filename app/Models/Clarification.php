<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Clarification extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contest_id',
        'site_id',
        'user_id',
        'problem_id',
        'clarification_number',
        'question',
        'answer',
        'contest_time',
        'answered_time',
        'status',
        'judge_id',
        'judge_site_id',
    ];

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\Helium\User::class, 'user_id', 'user_id');
    }

    public function problem(): BelongsTo
    {
        return $this->belongsTo(Problem::class);
    }

    public function judge(): BelongsTo
    {
        return $this->belongsTo(\Helium\User::class, 'judge_id', 'user_id');
    }

    public function judgeSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'judge_site_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isAnswered(): bool
    {
        return in_array($this->status, ['answered', 'broadcast_site', 'broadcast_all']);
    }

    public function isBroadcast(): bool
    {
        return in_array($this->status, ['broadcast_site', 'broadcast_all']);
    }

    public static function getNextClarificationNumber(int $contestId, int $siteId): int
    {
        return self::where('contest_id', $contestId)
            ->where('site_id', $siteId)
            ->max('clarification_number') + 1 ?? 1;
    }
}