<?php

namespace Helium;

use App\Models\Contest;
use App\Models\Site;
use App\Support\ProfilePrivacyPolicy;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory
     */
    protected static function newFactory()
    {
        return UserFactory::new();
    }

    protected $primaryKey = 'user_id';

    protected $fillable = [
        'fullname',
        'username',
        'email',
        'password',
        'user_type',
        'contest_id',
        'site_id',
        'description',
        'is_enabled',
        'birthdate',
        'managed_by',
        'profile_visibility',
        'managed_at',
    ];

    protected $guarded = ['user_id', 'created_at', 'updated_at'];

    /**
     * `birthdate` is deliberately hidden from ALL JSON/array serialization
     * (issue #47: "Não mostrar nascimento em listagem, placar, biblioteca ou
     * perfil público"), not just excluded from the managed-accounts
     * endpoint response. Several existing endpoints eager-load and
     * serialize the whole User model (e.g. Leaderboard::getScoreboard()'s
     * `'user' => $entry->user` inside the scoreboard API, and
     * Api\RunController's `$run->load([..., 'user', ...])`) -- hiding it
     * here is what actually keeps it out of those responses regardless of
     * what future code eager-loads.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'birthdate',
    ];

    protected $casts = [
        'birthdate' => 'date',
        'managed_at' => 'datetime',
        'is_enabled' => 'boolean',
    ];

    protected $attributes = [
        'user_type' => 'team', // Default to team (participant)
    ];

    // User type constants (BOCA compatible)
    const TYPE_ADMIN = 'admin';

    const TYPE_JUDGE = 'judge';

    const TYPE_TEAM = 'team';

    const TYPE_STAFF = 'staff';

    const TYPE_SCORE = 'score';

    const TYPE_SYSTEM = 'system';

    const TYPE_SITE = 'site';

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }

    /**
     * The admin who created this account via the managed-accounts flow
     * (issue #47). Null for self-registered accounts.
     */
    public function managedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'managed_by', 'user_id');
    }

    public function isAdmin(): bool
    {
        return in_array($this->user_type, [self::TYPE_ADMIN, self::TYPE_SYSTEM]);
    }

    public function isJudge(): bool
    {
        return $this->user_type === self::TYPE_JUDGE;
    }

    public function isParticipant(): bool
    {
        return $this->user_type === self::TYPE_TEAM;
    }

    public function isSpectator(): bool
    {
        return $this->user_type === self::TYPE_SCORE;
    }

    public function isStaff(): bool
    {
        return $this->user_type === self::TYPE_STAFF;
    }

    public function isSite(): bool
    {
        return $this->user_type === self::TYPE_SITE;
    }

    public function hasRole(string $roleName): bool
    {
        // Map role names to user types
        $roleMap = [
            'admin' => [self::TYPE_ADMIN, self::TYPE_SYSTEM],
            'participant' => [self::TYPE_TEAM],
            'spectator' => [self::TYPE_SCORE],
            'judge' => [self::TYPE_JUDGE],
            'staff' => [self::TYPE_STAFF],
            'site' => [self::TYPE_SITE],
        ];

        if (isset($roleMap[$roleName])) {
            return in_array($this->user_type, $roleMap[$roleName]);
        }

        return $this->user_type === $roleName;
    }

    public function canAccessBackend(): bool
    {
        return $this->isAdmin() || $this->isJudge() || $this->isStaff();
    }

    public function canSubmit(): bool
    {
        return $this->isAdmin() || $this->isParticipant();
    }

    public function canViewScoreboard(): bool
    {
        return true; // All roles can view scoreboard
    }

    public function canJudge(): bool
    {
        return $this->isAdmin() || $this->isJudge();
    }

    /**
     * Issue #47 -- thin wrappers over the central App\Support\
     * ProfilePrivacyPolicy. Delegating (rather than reimplementing the age
     * math here) keeps a single source of truth that future features (#43
     * Treino Livre, #44 webcast export) must also call into, so an unknown
     * birthdate can never resolve to an unrestricted/public state anywhere
     * in the app.
     */
    public function isMinor(): bool
    {
        return ProfilePrivacyPolicy::isMinor($this);
    }

    public function isAdult(): bool
    {
        return ProfilePrivacyPolicy::isAdult($this);
    }

    public function privacyLocked(): bool
    {
        return ProfilePrivacyPolicy::privacyLocked($this);
    }

    public function externalLinkingAllowed(): bool
    {
        return ProfilePrivacyPolicy::externalLinkingAllowed($this);
    }
}
