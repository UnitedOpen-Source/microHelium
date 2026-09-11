<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'start_time',
        'duration',
        'freeze_time',
        'penalty',
        'max_file_size',
        'is_active',
        'is_public',
        'unlock_key',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
    ];

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function languages(): HasMany
    {
        return $this->hasMany(Language::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    public function problems(): HasMany
    {
        return $this->hasMany(Problem::class)->orderBy('sort_order');
    }

    public function users(): HasMany
    {
        return $this->hasMany(\Helium\User::class);
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

    public function logs(): HasMany
    {
        return $this->hasMany(ContestLog::class);
    }

    public function getEndTimeAttribute(): ?\DateTime
    {
        if (!$this->start_time) {
            return null;
        }
        return $this->start_time->copy()->addMinutes($this->duration);
    }

    public function getFreezeTimeAttribute(): ?\DateTime
    {
        if (!$this->start_time) {
            return null;
        }
        return $this->end_time->copy()->subMinutes($this->attributes['freeze_time']);
    }

    public function isRunning(): bool
    {
        if (!$this->is_active || !$this->start_time) {
            return false;
        }
        $now = now();
        return $now->gte($this->start_time) && $now->lte($this->end_time);
    }

    public function isFrozen(): bool
    {
        if (!$this->isRunning()) {
            return false;
        }
        return now()->gte($this->freeze_time);
    }

    public function getContestTime(): int
    {
        if (!$this->start_time || now()->lt($this->start_time)) {
            return 0;
        }
        // $a->diffInSeconds($b) returns $b's timestamp minus $a's (signed,
        // not absolute, as of Carbon 3 -- this app's pinned version). Elapsed
        // time since start is "now minus start", so start_time must be the
        // receiver and now() the argument, not the other way around; the
        // previous now()->diffInSeconds($this->start_time) returned a
        // NEGATIVE value for the entire duration a contest is running,
        // silently corrupting every Run/Task/Clarification's stored
        // contest_time/judged_time/completed_time/answered_time and, via
        // Score::updateScore()'s solved_time = floor(contest_time / 60),
        // inverting the scoreboard's ranking (Leaderboard::getScoreboard()
        // sorts by total_time ascending, so a more-negative -- i.e. later
        // -- solve time ranked BETTER).
        return (int) $this->start_time->diffInSeconds(now());
    }
}
