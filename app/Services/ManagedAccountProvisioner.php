<?php

namespace App\Services;

use App\Models\AccountActivation;
use Helium\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Issue #47 -- the one place a managed team account comes into existence.
 *
 * This is the body that used to live inline in
 * Api\Frontend\ManagedAccountsController::store(). It was lifted out for
 * issue #141 (bulk import of the ICPC team file) so that importing 60 teams
 * and creating one team through the admin screen produce *identical*
 * accounts: no usable password, disabled until activation, private
 * profile, attributed to the admin who created them, and one single-use
 * activation token each.
 *
 * The alternative -- a second creation path in the importer that invented
 * its own credential scheme (BOCA generates and prints a 6-digit password,
 * src/admin/user.php) -- would have meant two different answers to "how
 * does a team get its password" in the same product, and a second place to
 * keep in sync every time the privacy rules in #47 change. The import is
 * therefore deliberately constrained to what this method can produce.
 */
class ManagedAccountProvisioner
{
    /**
     * 72h, as documented in docs/specs/47-managed-accounts.md. Kept here
     * rather than in the controller because the importer needs the same
     * default, and a bulk import run days before a contest may legitimately
     * want to override it (see TeamImportCommand's --activation-hours).
     */
    public const ACTIVATION_TTL_HOURS = 72;

    /**
     * `users.username` is globally unique (2017_06_17_011612_create_users_table),
     * not unique per contest -- so this check is deliberately unscoped.
     * Compared case- and whitespace-insensitively because "Equipe1" and
     * "equipe1 " are the same login to a human handing out credentials,
     * even though the unique index would happily accept both.
     */
    public static function usernameTaken(string $username): bool
    {
        return User::query()
            ->whereRaw('LOWER(TRIM(username)) = ?', [mb_strtolower(trim($username))])
            ->exists();
    }

    /**
     * @param  array{fullname: string, username: string, contest_id: int, site_id: int, birthdate?: ?string, description?: ?string, icpc_id?: ?string}  $attributes
     * @return array{0: User, 1: string} The user and its raw activation token (never persisted)
     *
     * @throws UsernameTakenException
     */
    public function create(array $attributes, ?int $managedBy, ?int $activationHours = null): array
    {
        $username = trim($attributes['username']);

        if (self::usernameTaken($username)) {
            throw new UsernameTakenException($username);
        }

        try {
            return DB::transaction(function () use ($attributes, $username, $managedBy, $activationHours) {
                $user = User::create([
                    'fullname' => trim($attributes['fullname']),
                    'username' => $username,
                    // Unusable password -- nobody can log in with this
                    // hash. The real credential is set by the recipient
                    // through AccountActivationController::store().
                    'password' => Hash::make(Str::random(64)),
                    'user_type' => User::TYPE_TEAM,
                    'contest_id' => $attributes['contest_id'],
                    'site_id' => $attributes['site_id'],
                    // Both null for the admin screen; the importer fills
                    // them from the ICPC file (institution name, team id).
                    'description' => $attributes['description'] ?? null,
                    'icpc_id' => $attributes['icpc_id'] ?? null,
                    // Disabled until activation completes -- "criar role
                    // team habilitada apenas conforme processo de
                    // ativacao".
                    'is_enabled' => false,
                    'birthdate' => $attributes['birthdate'] ?? null,
                    'managed_by' => $managedBy,
                    'managed_at' => now(),
                    'profile_visibility' => 'private',
                ]);

                $token = Str::random(64);
                AccountActivation::create([
                    'user_id' => $user->user_id,
                    // Only the hash is stored -- the raw token exists only
                    // in the URL handed back to the caller and in the
                    // recipient's link.
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => now()->addHours($activationHours ?? self::ACTIVATION_TTL_HOURS),
                ]);

                return [$user, $token];
            });
        } catch (QueryException $e) {
            // usernameTaken() above is a plain SELECT, not a lock -- two
            // requests (or an import racing an admin at the screen) can
            // both pass it before either commits. The DB's unique index on
            // users.username is the real guard in that race.
            //
            // This same transaction also inserts an AccountActivation row
            // with its own unique constraint on token_hash -- a blind "does
            // the message contain 'unique'" check would wrongly relabel
            // *that* collision as a username conflict too, hiding the real
            // cause. Match the username column specifically.
            if (self::isUsernameUniqueViolation($e)) {
                throw new UsernameTakenException($username, $e);
            }

            throw $e;
        }
    }

    /**
     * Relative activation URL for a raw token. Relative, not absolute:
     * APP_URL is frequently wrong or unset on a contest host (the
     * organisers reach it by LAN IP), and printing a confidently wrong
     * absolute link into 60 credential slips is worse than printing a path
     * the organiser prefixes themselves.
     */
    public static function activationPath(string $token): string
    {
        return '/activate/'.$token;
    }

    /**
     * True only for a unique-constraint violation on users.username
     * specifically -- see the catch block above for why a blind "unique"
     * substring match isn't precise enough here.
     */
    private static function isUsernameUniqueViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique') && str_contains($message, 'username');
    }
}
