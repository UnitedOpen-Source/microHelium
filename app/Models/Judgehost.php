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
        'last_seen_at',
        'created_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Never serialised anywhere: the raw token exists only in the response
     * that creates the credential.
     */
    protected $hidden = ['token_hash'];

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

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
