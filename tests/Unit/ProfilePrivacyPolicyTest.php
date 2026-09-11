<?php

namespace Tests\Unit;

use App\Support\ProfilePrivacyPolicy;
use Carbon\CarbonImmutable;
use Helium\User;
use Tests\TestCase;

/**
 * Issue #47 -- documents and locks in the two decisions the spec explicitly
 * asked for: the timezone used to compute age (config('app.timezone')) and
 * the Feb 29 leap-year policy (turns each age on March 1 of a non-leap
 * anniversary year).
 */
class ProfilePrivacyPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_unknown_birthdate_is_never_resolved_as_adult(): void
    {
        $this->assertSame(ProfilePrivacyPolicy::STATUS_UNKNOWN, ProfilePrivacyPolicy::ageStatus(null));
        $this->assertSame(ProfilePrivacyPolicy::STATUS_UNKNOWN, ProfilePrivacyPolicy::ageStatus(''));
    }

    public function test_still_minor_the_day_before_the_18th_birthday(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 14, 12, 0, 0, config('app.timezone')));

        $this->assertSame(ProfilePrivacyPolicy::STATUS_MINOR, ProfilePrivacyPolicy::ageStatus('2008-06-15'));
    }

    public function test_becomes_adult_exactly_on_the_18th_birthday(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 15, 0, 0, 1, config('app.timezone')));

        $this->assertSame(ProfilePrivacyPolicy::STATUS_ADULT, ProfilePrivacyPolicy::ageStatus('2008-06-15'));
    }

    public function test_feb_29_birthdate_stays_minor_on_feb_28_of_a_non_leap_18th_year(): void
    {
        // 2008 is a leap year (a Feb 29 birth is possible). 2008 + 18 =
        // 2026, which is NOT a leap year -- the documented policy says this
        // person turns 18 on March 1, 2026, not Feb 28.
        $this->assertTrue(checkdate(2, 29, 2008));
        $this->assertFalse(checkdate(2, 29, 2026));

        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 2, 28, 23, 59, 59, config('app.timezone')));

        $this->assertSame(ProfilePrivacyPolicy::STATUS_MINOR, ProfilePrivacyPolicy::ageStatus('2008-02-29'));
    }

    public function test_feb_29_birthdate_becomes_adult_on_march_1_of_a_non_leap_18th_year(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 3, 1, 0, 0, 0, config('app.timezone')));

        $this->assertSame(ProfilePrivacyPolicy::STATUS_ADULT, ProfilePrivacyPolicy::ageStatus('2008-02-29'));
    }

    public function test_privacy_locked_and_external_linking_across_all_three_states(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 15, 0, 0, 0, config('app.timezone')));

        $unknown = User::factory()->make(['birthdate' => null]);
        $minor = User::factory()->make(['birthdate' => '2010-01-01']);
        $adult = User::factory()->make(['birthdate' => '2008-06-15']);

        $this->assertTrue(ProfilePrivacyPolicy::privacyLocked($unknown), 'unknown must stay locked');
        $this->assertTrue(ProfilePrivacyPolicy::privacyLocked($minor), 'minor must stay locked');
        $this->assertFalse(ProfilePrivacyPolicy::privacyLocked($adult), 'adult unlocks eligibility');

        $this->assertFalse(ProfilePrivacyPolicy::externalLinkingAllowed($unknown));
        $this->assertFalse(ProfilePrivacyPolicy::externalLinkingAllowed($minor));
        $this->assertTrue(ProfilePrivacyPolicy::externalLinkingAllowed($adult));
    }

    public function test_reason_text_differs_between_locked_and_unlocked_but_never_reveals_the_birthdate(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 15, 0, 0, 0, config('app.timezone')));

        $minor = User::factory()->make(['birthdate' => '2010-01-01']);
        $adult = User::factory()->make(['birthdate' => '2008-06-15']);

        $this->assertStringContainsString('protegida', ProfilePrivacyPolicy::reason($minor));
        $this->assertStringContainsString('liberada', ProfilePrivacyPolicy::reason($adult));
        $this->assertStringNotContainsString('2010', ProfilePrivacyPolicy::reason($minor));
        $this->assertStringNotContainsString('2008', ProfilePrivacyPolicy::reason($adult));
    }

    public function test_user_model_wrapper_methods_delegate_to_the_policy(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 15, 0, 0, 0, config('app.timezone')));

        $adult = User::factory()->make(['birthdate' => '2008-06-15']);
        $minor = User::factory()->make(['birthdate' => '2010-01-01']);

        $this->assertTrue($adult->isAdult());
        $this->assertFalse($adult->isMinor());
        $this->assertFalse($adult->privacyLocked());
        $this->assertTrue($adult->externalLinkingAllowed());

        $this->assertTrue($minor->isMinor());
        $this->assertFalse($minor->isAdult());
        $this->assertTrue($minor->privacyLocked());
        $this->assertFalse($minor->externalLinkingAllowed());
    }
}
