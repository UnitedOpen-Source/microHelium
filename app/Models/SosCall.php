<?php

namespace App\Models;

use Helium\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #139 -- a team's call for the local staff of its own site.
 *
 * The state machine lives here rather than in the controllers because two
 * surfaces move it (the staff queue today; a site-coordinator screen or the
 * API tomorrow) and the transitions have to mean the same thing in both.
 *
 *     open ---------> acknowledged ---------> resolved
 *       \                                       ^
 *        \-------------------------------------/
 *
 * open -> resolved directly is legal on purpose: the common case at a real
 * contest is that a staff member reads the row, walks over, swaps the
 * keyboard and comes back. Forcing them to press "acknowledge" first for a
 * thing they already finished would only teach them to press both buttons
 * in a row, which makes the acknowledged state noise.
 *
 * `resolved` is terminal, and there is no reopen. A call reopened weeks of
 * contest-minutes later would report a `contest_time` that is not when the
 * problem happened; a team whose trouble came back raises a new one, which
 * the dedupe slot has just freed.
 */
class SosCall extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_RESOLVED = 'resolved';

    /**
     * Value of `active_slot` while a call still needs someone. See the
     * migration: NULL once resolved, which is what frees the team to raise
     * another one under the unique index.
     */
    public const ACTIVE_SLOT = 1;

    protected $fillable = [
        'contest_id',
        'site_id',
        'user_id',
        'note',
        'status',
        'contest_time',
        'acknowledged_at',
        'acknowledged_by',
        'resolved_at',
        'resolved_by',
        'active_slot',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /** @return BelongsTo<Contest, $this> */
    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by', 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by', 'user_id');
    }

    /**
     * Still needs a human: open or acknowledged, never resolved.
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_RESOLVED);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isAcknowledged(): bool
    {
        return $this->status === self::STATUS_ACKNOWLEDGED;
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    /**
     * "Someone is on their way." Only from `open` -- acknowledging twice
     * would overwrite who took it and when, and acknowledging something
     * already resolved is meaningless.
     *
     * @return bool whether the transition was legal and applied
     */
    public function acknowledge(User $staff): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => now(),
            'acknowledged_by' => $staff->user_id,
            // Still unresolved, so the team still cannot raise a second one.
            'active_slot' => self::ACTIVE_SLOT,
        ]);
    }

    /**
     * "Dealt with." Legal from either live state; a no-op on a call someone
     * else already closed, so a double submit from two staff members racing
     * the same row does not rewrite the first one's attribution.
     *
     * @return bool whether the transition was legal and applied
     */
    public function resolve(User $staff): bool
    {
        if ($this->isResolved()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_RESOLVED,
            'resolved_at' => now(),
            'resolved_by' => $staff->user_id,
            // Frees the unique slot: this team may call again if they need to.
            'active_slot' => null,
        ]);
    }
}
