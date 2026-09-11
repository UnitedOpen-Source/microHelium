<?php

namespace App\Models;

use Helium\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #46's proposed membership role: currently only "editor" is granted
 * any capability (editing tags/owner of their own organization's problem
 * bank items). See app/Policies/ProblemBankPolicy.php.
 */
class OrganizationMembership extends Model
{
    public const ROLE_EDITOR = 'editor';

    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
