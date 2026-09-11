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
        'score_visibility',
        'max_judge_wait_time',
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

    /**
     * Routes where this site's judges also handle another site's runs.
     */
    public function judgingRoutes(): HasMany
    {
        return $this->hasMany(SiteJudgingRoute::class, 'host_site_id');
    }

    /**
     * This site's own id plus every source_site_id explicitly routed to it.
     * Used to scope which runs a judge stationed at this site can see --
     * an empty routing table means "own site only", matching BOCA's
     * sitejudging default.
     */
    public function routedJudgingSiteIds(): array
    {
        return array_merge([$this->id], $this->judgingRoutes()->pluck('source_site_id')->all());
    }

    public function getEffectiveDuration(): int
    {
        return $this->duration ?? $this->contest->duration;
    }

    public function getEffectiveFreezeTime(): int
    {
        return $this->freeze_time ?? $this->contest->getAttributes()['freeze_time'];
    }

    /**
     * Enforces the ip_address field (issue #50) -- it was collected via the
     * admin Sites CRUD (#40) and validated, but nothing anywhere actually
     * read it. Supports a comma-separated list of exact IPs and/or CIDR
     * ranges (the field's label already says "IP / rede"). No value
     * configured means unrestricted.
     */
    public function isIpAllowed(string $ip): bool
    {
        if (!$this->ip_address) {
            return true;
        }

        foreach (explode(',', $this->ip_address) as $rule) {
            $rule = trim($rule);
            if ($rule !== '' && \App\Support\NetworkMatcher::matches($ip, $rule)) {
                return true;
            }
        }

        return false;
    }
}
