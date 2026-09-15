<?php

namespace App\Models;

use Helium\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Clarification extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Issue #197 -- the desks a clarification can be addressed to.
     *
     * The CCS requirements name exactly these three plus "one category per
     * problem"; the per-problem half is `problem_id`, which already
     * existed. A question about a keyboard and a question about the
     * statement of problem C go to different people, and until now both
     * landed in one queue.
     */
    public const CATEGORY_GENERAL = 'general';

    public const CATEGORY_SYSOPS = 'sysops';

    public const CATEGORY_OPERATIONS = 'operations';

    public const CATEGORIES = [
        self::CATEGORY_GENERAL,
        self::CATEGORY_SYSOPS,
        self::CATEGORY_OPERATIONS,
    ];

    protected $fillable = [
        'contest_id',
        'site_id',
        'user_id',
        'problem_id',
        'category',
        'clarification_number',
        'question',
        'answer',
        'contest_time',
        'answered_time',
        'status',
        'judge_id',
        'judge_site_id',
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

    /** @return BelongsTo<Problem, $this> */
    public function problem(): BelongsTo
    {
        return $this->belongsTo(Problem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function judge(): BelongsTo
    {
        return $this->belongsTo(User::class, 'judge_id', 'user_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function judgeSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'judge_site_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isAnswered(): bool
    {
        return in_array($this->status, ['answered', 'broadcast_site', 'broadcast_all']);
    }

    public function isBroadcast(): bool
    {
        return in_array($this->status, ['broadcast_site', 'broadcast_all']);
    }

    public static function getNextClarificationNumber(int $contestId, int $siteId): int
    {
        return self::where('contest_id', $contestId)
            ->where('site_id', $siteId)
            ->max('clarification_number') + 1 ?? 1;
    }
}
