<?php

namespace App\Models;

use Helium\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Issue #53 -- a judge machine allowed to pull work.
 *
 * Identity comes from the token, never from anything the caller says about
 * itself: DOMjudge reads the hostname out of the request body and
 * authenticates with a password shared by every host, so one credential
 * impersonates any of them.
 */
class Judgehost extends Model
{
    protected $fillable = [
        'name',
        'token_hash',
        'enabled',
        'cpu_count',
        'memory_mb',
        'last_seen_at',
        'created_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'cpu_count' => 'integer',
        'memory_mb' => 'integer',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Never serialised anywhere: the raw token exists only in the response
     * that creates the credential.
     */
    protected $hidden = ['token_hash'];

    /** @return HasMany<Run, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * Issue #117 -- the languages this machine reported it can run.
     */
    /** @return HasMany<JudgehostCapability, $this> */
    public function capabilities(): HasMany
    {
        return $this->hasMany(JudgehostCapability::class);
    }

    /**
     * Replace what this host says it can run.
     *
     * Called on register, so a machine that had a runtime installed or
     * removed corrects itself by restarting its agent rather than by
     * someone remembering to edit a list.
     *
     * @param  list<string>  $extensions
     */
    public function declareCapabilities(array $extensions): void
    {
        $extensions = collect($extensions)
            ->filter(fn ($extension) => is_string($extension) && $extension !== '')
            ->map(fn (string $extension) => mb_substr($extension, 0, 20))
            ->unique()
            ->values();

        $this->capabilities()->whereNotIn('extension', $extensions)->delete();

        foreach ($extensions as $extension) {
            $this->capabilities()->firstOrCreate(['extension' => $extension]);
        }

        $this->unsetRelation('capabilities');
    }

    /**
     * Can this host judge a submission in $extension?
     *
     * A host that has declared nothing is treated as able to judge
     * anything, which is what an agent older than #117 does. Refusing it
     * work instead would take a judge offline on upgrade, and #125 already
     * makes the failure visible and bounded: it gives the run back with a
     * reason, and a run refused enough times stops being offered.
     */
    public function canJudge(?string $extension): bool
    {
        $declared = $this->relationLoaded('capabilities')
            ? $this->capabilities
            : $this->capabilities()->get();

        if ($declared->isEmpty()) {
            return true;
        }

        return $declared->contains('extension', $extension);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Creates a judgehost and returns it with the one and only copy of its
     * token. Callers must show that token once and then forget it.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name, ?int $createdBy = null): array
    {
        $token = Str::random(48);

        $judgehost = self::create([
            'name' => $name,
            'token_hash' => self::hashToken($token),
            'enabled' => true,
            'created_by' => $createdBy,
        ]);

        return [$judgehost, $token];
    }

    /**
     * The host this token belongs to, or null. Disabled hosts do not
     * resolve -- `enabled` is the kill switch an organiser reaches for when
     * a machine is misbehaving mid-contest.
     */
    public static function authenticate(?string $token): ?self
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        // Looked up by hash rather than compared in PHP: the digest is
        // unique and indexed, and there is nothing secret about the lookup
        // itself once the token is already hashed.
        return self::query()->enabled()->where('token_hash', self::hashToken($token))->first();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
