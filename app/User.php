<?php

namespace Helium;

use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Site;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use Notifiable, HasFactory, HasApiTokens;

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return \Database\Factories\UserFactory::new();
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
    ];

    protected $guarded = ['user_id', 'created_at', 'updated_at'];

    protected $hidden = [
        'password',
        'remember_token',
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

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
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

    public function hasRole(string $roleName): bool
    {
        // Map role names to user types
        $roleMap = [
            'admin' => [self::TYPE_ADMIN, self::TYPE_SYSTEM],
            'participant' => [self::TYPE_TEAM],
            'spectator' => [self::TYPE_SCORE],
            'judge' => [self::TYPE_JUDGE],
            'staff' => [self::TYPE_STAFF],
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
}
