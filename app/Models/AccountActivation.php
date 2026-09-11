<?php

namespace App\Models;

use Helium\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #47 -- one-time activation token for a managed account created
 * without a usable password. Only `token_hash` (sha256 of the raw token) is
 * ever persisted; the raw token exists only in the `activation_url`
 * returned once at creation time and in the URL the recipient visits.
 */
class AccountActivation extends Model
{
    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * Single source of truth for "this token may still be used." Shared
     * between isValid() below (a read-only check against an
     * already-loaded instance, used by
     * AccountActivationController::show() to decide what to render) and
     * that same controller's store(), which builds its atomic claim
     * UPDATE's WHERE clause from this scope instead of hand-rewriting the
     * same two conditions as a separate query -- so a future change to
     * this rule (e.g. adding a "revoked" state) only has to happen here,
     * not in both places independently.
     */
    public function scopeValid($query)
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    /**
     * Attribute-based equivalent of scopeValid() above, for an
     * already-loaded instance -- must be kept in sync with it.
     */
    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isUsed();
    }
}
