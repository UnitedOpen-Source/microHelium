<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Helium\User;

/**
 * Issue #47 -- central privacy/age policy for managed accounts.
 *
 * Distinguishes three birthdate states -- "unknown" (birthdate is null),
 * "minor" (age < 18) and "adult" (age >= 18) -- and is written so "unknown"
 * NEVER resolves to an unrestricted/public state (spec: "Data desconhecida:
 * conservar restrição até revisão"; "isMinor() não deve transformar
 * nascimento desconhecido em autorização pública").
 *
 * Any future feature that exposes a user's history/profile (#43 Treino
 * Livre, #44 webcast export, small aggregates, legacy APIs) MUST call
 * privacyLocked()/externalLinkingAllowed() from here instead of
 * reimplementing an age check, so the "unknown stays restricted" guarantee
 * holds everywhere birthdate-derived privacy matters.
 *
 * Timezone decision: age is computed in config('app.timezone') (the
 * APP_TIMEZONE env var, defaulting to UTC) -- the same timezone the rest of
 * the application uses for now(). This is a deliberate, documented choice
 * per the spec's "escolher e testar política" instruction.
 *
 * Feb 29 leap-year decision: a person born on Feb 29 turns each age on
 * March 1st of a non-leap anniversary year (there is no Feb 29 to turn on
 * that year). Deterministic and covered by
 * tests/Unit/ProfilePrivacyPolicyTest.php.
 *
 * Turning 18 lifts ELIGIBILITY only (external_linking_allowed becomes
 * available) -- it never flips `profile_visibility` to public. There is no
 * mechanism in this codebase to publish a profile; that is an explicit,
 * open maintainer decision (see docs/specs/47-managed-accounts.md).
 */
class ProfilePrivacyPolicy
{
    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_MINOR = 'minor';

    public const STATUS_ADULT = 'adult';

    /**
     * @param  DateTimeInterface|string|null  $birthdate  A Y-m-d string, a
     *                                                    DateTimeInterface (e.g. the `birthdate` Eloquent date cast), or
     *                                                    null for "not informed".
     */
    public static function ageStatus(DateTimeInterface|string|null $birthdate): string
    {
        if ($birthdate === null || $birthdate === '') {
            return self::STATUS_UNKNOWN;
        }

        $timezone = config('app.timezone');

        $birth = $birthdate instanceof DateTimeInterface
            ? CarbonImmutable::instance($birthdate)->setTimezone($timezone)->startOfDay()
            : CarbonImmutable::createFromFormat('Y-m-d', substr($birthdate, 0, 10), $timezone)->startOfDay();

        $turns18 = self::anniversary($birth, 18, $timezone);

        return CarbonImmutable::now($timezone)->greaterThanOrEqualTo($turns18)
            ? self::STATUS_ADULT
            : self::STATUS_MINOR;
    }

    /**
     * The exact instant (00:00 local product timezone) a person born on
     * $birth turns $age years old, applying the Feb 29 -> March 1 policy
     * documented on this class.
     */
    public static function anniversary(CarbonImmutable $birth, int $age, ?string $timezone = null): CarbonImmutable
    {
        $timezone = $timezone ?? config('app.timezone');
        $year = $birth->year + $age;
        $month = $birth->month;
        $day = $birth->day;

        if ($month === 2 && $day === 29 && ! checkdate(2, 29, $year)) {
            return CarbonImmutable::create($year, 3, 1, 0, 0, 0, $timezone);
        }

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, $timezone);
    }

    public static function isMinor(User $user): bool
    {
        return self::ageStatus($user->birthdate) === self::STATUS_MINOR;
    }

    public static function isAdult(User $user): bool
    {
        return self::ageStatus($user->birthdate) === self::STATUS_ADULT;
    }

    /**
     * True for both "minor" and "unknown" -- an unreviewed/unknown
     * birthdate keeps every restriction a minor has (spec: "Data
     * desconhecida: conservar restrição até revisão").
     */
    public static function privacyLocked(User $user): bool
    {
        return self::ageStatus($user->birthdate) !== self::STATUS_ADULT;
    }

    /**
     * Eligibility only -- does NOT imply `profile_visibility` is public.
     */
    public static function externalLinkingAllowed(User $user): bool
    {
        return ! self::privacyLocked($user);
    }

    public static function reason(User $user): string
    {
        return self::reasonForLockedState(self::privacyLocked($user));
    }

    /**
     * Same text as reason(), but takes an already-computed privacyLocked()
     * result -- lets a caller that needs more than one of privacyLocked()/
     * externalLinkingAllowed()/reason() for the same user (e.g. a paginated
     * listing) compute ageStatus() once per user instead of once per field.
     */
    public static function reasonForLockedState(bool $locked): string
    {
        return $locked
            ? 'Privacidade protegida pela organização.'
            : 'A restrição etária foi liberada. A conta permanece privada.';
    }
}
