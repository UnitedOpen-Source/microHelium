<?php

namespace App\Models;

use Helium\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Contest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'start_time',
        'duration',
        'freeze_time',
        'penalty',
        'max_file_size',
        'is_active',
        'is_public',
        'is_practice',
        'verification_required',
        'unlock_key',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'is_practice' => 'boolean',
        // Issue #138 -- DOMjudge's "Is manual verification of judgings by
        // jury required before publication?", per contest rather than per
        // installation (see the migration for why).
        'verification_required' => 'boolean',
    ];

    /**
     * Issue #43 -- everything that means "a competition" must exclude the
     * technical practice contest: active-contest selection, the public
     * selector, the clock, global activation, event CSV, tasks/balloons and
     * event ranking (docs/specs/43-practice.md).
     *
     * Deliberately a scope rather than a global scope: a global one would
     * also apply to $run->contest, and AutoJudgeService needs that
     * relationship to resolve for practice runs too.
     */
    public function scopeCompetition($query)
    {
        return $query->where('is_practice', false);
    }

    public function scopePractice($query)
    {
        return $query->where('is_practice', true);
    }

    /**
     * Issue #134 -- may this viewer see this contest at all?
     *
     * The same rule issue #135 landed for the web side in
     * App\Http\Controllers\ProblemController::mayList(), in the same order:
     * staff always; then someone who belongs to this contest; then everyone,
     * but only when the contest is marked public. It lives on the model
     * because the API asks it of four different controllers (contest,
     * problem, scoreboard, clarification) -- one rule with one home, so the
     * listing and the detail endpoint cannot drift apart the way #135 found
     * them drifted on the web side.
     *
     * The gate matters most before an event opens: is_public defaults to
     * false, and a contest that has not started still has its whole problem
     * set loaded.
     */
    public function isVisibleTo(?User $user): bool
    {
        if ($user?->isAdmin() || $user?->isJudge()) {
            return true;
        }

        if (in_array((int) $this->id, self::memberContestIds($user), true)) {
            return true;
        }

        return (bool) $this->is_public;
    }

    /**
     * The row-set half of isVisibleTo(), for the listings.
     *
     * Kept beside it on purpose: a listing that filters by a different rule
     * than the one the detail endpoint enforces is exactly the bug #135
     * describes, where /exercises listed problems whose own page answered
     * 404.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        if ($user?->isAdmin() || $user?->isJudge()) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->where('is_public', true)
                ->orWhereIn('id', self::memberContestIds($user));
        });
    }

    /**
     * Which contests this user belongs to.
     *
     * Broader than #135's mayList(), which asks about users.contest_id
     * alone, and deliberately so: in the BOCA schema a user reaches a
     * contest through either column, and the API's own writers already treat
     * the site as authoritative when the direct link is absent
     * (`$user->site_id ?? $contest->sites()->first()->id` in
     * Api\RunController::store() and Api\ClarificationController::store()).
     * Reading users.contest_id only would lock a legitimately registered
     * team out of its own contest whenever registration filled in the site.
     *
     * @return list<int>
     */
    private static function memberContestIds(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return array_values(array_unique(array_filter([
            (int) $user->contest_id,
            (int) $user->site?->contest_id,
        ])));
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /** @return HasMany<Language, $this> */
    public function languages(): HasMany
    {
        return $this->hasMany(Language::class);
    }

    /** @return HasMany<Answer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    /** @return HasMany<Problem, $this> */
    public function problems(): HasMany
    {
        return $this->hasMany(Problem::class)->orderBy('sort_order');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Run, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /** @return HasMany<Clarification, $this> */
    public function clarifications(): HasMany
    {
        return $this->hasMany(Clarification::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<ContestLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(ContestLog::class);
    }

    /**
     * Carbon and not \DateTime: `start_time` has a `datetime` cast, so what
     * comes back is a Carbon, and this method's own body calls ->copy(),
     * which \DateTime does not have -- the declared type promised less than
     * the code relied on, and PHPStan is what noticed. Narrowing is safe
     * for every caller, because Carbon IS a \DateTime.
     */
    public function getEndTimeAttribute(): ?Carbon
    {
        if (! $this->start_time) {
            return null;
        }

        return $this->start_time->copy()->addMinutes($this->duration);
    }

    public function getFreezeTimeAttribute(): ?Carbon
    {
        if (! $this->start_time) {
            return null;
        }

        return $this->end_time->copy()->subMinutes($this->attributes['freeze_time']);
    }

    public function isRunning(): bool
    {
        if (! $this->is_active || ! $this->start_time) {
            return false;
        }
        $now = now();

        return $now->gte($this->start_time) && $now->lte($this->end_time);
    }

    public function isFrozen(): bool
    {
        if (! $this->isRunning()) {
            return false;
        }

        return now()->gte($this->freeze_time);
    }

    public function getContestTime(): int
    {
        if (! $this->start_time || now()->lt($this->start_time)) {
            return 0;
        }

        // $a->diffInSeconds($b) returns $b's timestamp minus $a's (signed,
        // not absolute, as of Carbon 3 -- this app's pinned version). Elapsed
        // time since start is "now minus start", so start_time must be the
        // receiver and now() the argument, not the other way around; the
        // previous now()->diffInSeconds($this->start_time) returned a
        // NEGATIVE value for the entire duration a contest is running,
        // silently corrupting every Run/Task/Clarification's stored
        // contest_time/judged_time/completed_time/answered_time and, via
        // Score::updateScore()'s solved_time = floor(contest_time / 60),
        // inverting the scoreboard's ranking (Leaderboard::getScoreboard()
        // sorts by total_time ascending, so a more-negative -- i.e. later
        // -- solve time ranked BETTER).
        return (int) $this->start_time->diffInSeconds(now());
    }
}
