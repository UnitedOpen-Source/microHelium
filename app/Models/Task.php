<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contest_id',
        'site_id',
        'user_id',
        'task_number',
        'description',
        'filename',
        'file_path',
        'contest_time',
        'completed_time',
        'status',
        'is_system',
        'color_name',
        'color_hex',
        'staff_id',
        'staff_site_id',
    ];

    protected $casts = [
        'is_system' => 'boolean',
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

    public function staff(): BelongsTo
    {
        return $this->belongsTo(\Helium\User::class, 'staff_id', 'user_id');
    }

    public function staffSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'staff_site_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isDone(): bool
    {
        return $this->status === 'done';
    }

    public static function getNextTaskNumber(int $contestId, int $siteId): int
    {
        return self::where('contest_id', $contestId)
            ->where('site_id', $siteId)
            ->max('task_number') + 1 ?? 1;
    }
}