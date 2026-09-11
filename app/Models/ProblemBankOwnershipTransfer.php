<?php

namespace App\Models;

use Helium\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit row for issue #46's ownership transfers ("Auditar transferências
 * com origem/destino, ator e tempo."). Append-only: one row is written per
 * PATCH to /api/frontend/bank-governance/{id} that actually changes
 * owning_org_id. No `updated_at` -- audit rows are never edited.
 */
class ProblemBankOwnershipTransfer extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'problem_bank_id',
        'from_organization_id',
        'to_organization_id',
        'actor_user_id',
    ];

    public function problemBank(): BelongsTo
    {
        return $this->belongsTo(ProblemBank::class);
    }

    public function fromOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'from_organization_id');
    }

    public function toOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'to_organization_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', 'user_id');
    }
}
