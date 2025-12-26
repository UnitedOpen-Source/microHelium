<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    protected $primaryKey = 'role_id';

    protected $fillable = [
        'name',
        'display_name',
        'description',
    ];

    const ADMIN = 'admin';
    const PARTICIPANT = 'participant';
    const SPECTATOR = 'spectator';

    public function users(): HasMany
    {
        return $this->hasMany(\Helium\User::class, 'role_id', 'role_id');
    }

    public static function getAdminId(): int
    {
        return 1;
    }

    public static function getParticipantId(): int
    {
        return 2;
    }

    public static function getSpectatorId(): int
    {
        return 3;
    }
}
