<?php

namespace App\Models;

use Helium\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Issue #192 -- um rejulgamento em lote, aplicavel ou cancelavel inteiro.
 */
class Rejudging extends Model
{
    use HasFactory;

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_READY = 'ready';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'contest_id',
        'reason',
        'filters',
        'include_accepted',
        'status',
        'created_by',
        'applied_at',
        'applied_by',
        'cancelled_at',
        'cancelled_by',
    ];

    protected $casts = [
        'filters' => 'array',
        'include_accepted' => 'boolean',
        'applied_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** @return BelongsTo<Contest, $this> */
    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    /** @return HasMany<RejudgingRun, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(RejudgingRun::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    /**
     * Um conjunto so pode ser aplicado ou cancelado uma vez, e so depois de
     * todos os membros terem sido julgados.
     *
     * `ready` e nao `preparing`: aplicar no meio da preparacao gravaria os
     * membros ja julgados e deixaria o resto para tras -- meio conjunto, que
     * e exatamente o que "aplicavel como conjunto" existe para impedir.
     */
    public function isDecidable(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PREPARING, self::STATUS_READY], true);
    }
}
