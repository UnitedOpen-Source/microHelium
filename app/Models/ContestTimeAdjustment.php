<?php

namespace App\Models;

use Helium\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Issue #198 -- um pedaco de tempo que a prova nao conta.
 *
 * `site_id` nulo e a prova inteira (o intervalo removido que a ICPC
 * especifica); preenchido e so aquela sede (a extensao por sede, que e o
 * caso real da Maratona quando cai a energia num lugar so).
 */
class ContestTimeAdjustment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contest_id',
        'site_id',
        'starts_at',
        'ends_at',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function seconds(): int
    {
        return max(0, (int) $this->starts_at->diffInSeconds($this->ends_at));
    }

    /**
     * Vale para esta sede?
     *
     * Um intervalo global vale para todas, inclusive para um run sem sede.
     */
    public function appliesToSite(?int $siteId): bool
    {
        return $this->site_id === null || (int) $this->site_id === $siteId;
    }
}
