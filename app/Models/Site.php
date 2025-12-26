<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contest_id',
        'name',
        'ip_address',
        'is_active',
        'permit_logins',
        'auto_judge',
        'duration',
        'freeze_time',
        'max_runtime',
        'chief_judge_name',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'permit_logins' => 'boolean',
        'auto_judge' => 'boolean',
    ];

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(\Helium\User::class, 'site_id', 'id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    public function clarifications(): HasMany
    {
        return $this->hasMany(Clarification::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function getEffectiveDuration(): int
    {
        return $this->duration ?? $this->contest->duration;
    }

    public function getEffectiveFreezeTime(): int
    {
        return $this->freeze_time ?? $this->contest->getAttributes()['freeze_time'];
    }
}
