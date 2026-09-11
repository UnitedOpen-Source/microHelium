<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Issue #46's proposed owning organization for problem-bank items. This is
 * intentionally minimal: creation/management of organizations is a separate
 * phase (docs/specs/46-bank-ownership.md), so rows exist only via
 * seeding/tinker until that phase ships.
 */
class Organization extends Model
{
    protected $fillable = [
        'name',
        'archived_at',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function problemBankItems(): HasMany
    {
        return $this->hasMany(ProblemBank::class, 'owning_org_id');
    }
}
