<?php

namespace App\Models;

use Helium\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Issue #44 -- a revocable, read-only credential scoped to exactly one
 * contest. `token_hash` is the only trace of the secret ever stored; the
 * raw secret is generated in issue() and returned to the caller exactly
 * once (see App\Http\Controllers\Frontend\WebcastController::
 * storeCredential()) and never logged or persisted anywhere in cleartext.
 */
class WebcastCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'contest_id',
        'label',
        'token_hash',
        'expires_at',
        'revoked_at',
        'last_used_at',
        'created_by',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    /**
     * Generates a fresh credential for $contest and persists only its
     * hash. Returns [WebcastCredential $model, string $rawSecret] -- the
     * raw secret is the caller's only chance to read it; it is not
     * retrievable afterwards by any other code path.
     */
    public static function issue(Contest $contest, string $label, \DateTimeInterface $expiresAt, ?int $createdBy): array
    {
        $raw = Str::random(40);

        $credential = self::create([
            'contest_id' => $contest->id,
            'label' => $label,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
        ]);

        return [$credential, $raw];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function status(): string
    {
        if ($this->isRevoked()) {
            return 'revoked';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        return 'active';
    }

    public function revoke(): void
    {
        if (! $this->isRevoked()) {
            $this->forceFill(['revoked_at' => now()])->save();
        }
    }
}
