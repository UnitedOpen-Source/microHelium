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
        // Issue #270 -- identidade externa. `organizations` guardava o
        // minimo (id, name, archived_at), e a Contest API da ICPC define os
        // tres campos abaixo no recurso: sem identificador externo, um
        // agregado nacional nao consegue dizer que a "UFMG" de uma edicao e
        // a "UFMG" de outra.
        'icpc_id',
        'formal_name',
        'country',
        'archived_at',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    /** @return HasMany<OrganizationMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /** @return HasMany<ProblemBank, $this> */
    public function problemBankItems(): HasMany
    {
        return $this->hasMany(ProblemBank::class, 'owning_org_id');
    }
}
