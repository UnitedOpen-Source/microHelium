<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A row in the `roles` table, which is seeded and read by nothing.
 *
 * This application does not authorize through roles. It authorizes through
 * `users.user_type`: Helium\User::hasRole() maps a role NAME onto the set
 * of user_type values that count as that role, and
 * App\Http\Middleware\CheckRole is the only caller. The `roles`,
 * `role_user` and `permissions` tables come from the Entrust package that
 * the 2017 migration set up, and the 2025 migration seeds three rows into
 * `roles` -- nothing reads them.
 *
 * Said here because the alternative is a reader concluding that roles are
 * the mechanism, wiring something to them, and finding out at run time that
 * `role_user` is empty and always will be. If the roles system is ever made
 * real, the link to users is `belongsToMany` through `role_user`, which is
 * what the schema actually has.
 */
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
