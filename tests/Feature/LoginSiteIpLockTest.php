<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Issue #50: Site::ip_address was collected via the admin UI (#40) but
 * never enforced anywhere -- purely decorative until now.
 */
class LoginSiteIpLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_succeeds_from_the_sites_configured_ip()
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id, 'ip_address' => '203.0.113.5']);
        $this->createTestUser([
            'email' => 'team@example.com',
            'password' => Hash::make('password123'),
            'site_id' => $site->id,
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'team@example.com',
            'password' => 'password123',
        ], ['REMOTE_ADDR' => '203.0.113.5']);

        $response->assertRedirect('/home');
        $this->assertAuthenticated();
    }

    public function test_login_is_blocked_from_an_ip_outside_the_sites_configured_network()
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id, 'ip_address' => '203.0.113.5']);
        $this->createTestUser([
            'email' => 'team@example.com',
            'password' => Hash::make('password123'),
            'site_id' => $site->id,
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'team@example.com',
            'password' => 'password123',
        ], ['REMOTE_ADDR' => '198.51.100.9']);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_allowed_from_anywhere_within_a_configured_cidr_range()
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id, 'ip_address' => '192.168.1.0/24']);
        $this->createTestUser([
            'email' => 'team@example.com',
            'password' => Hash::make('password123'),
            'site_id' => $site->id,
        ]);

        $response = $this->post('/login', [
            'email' => 'team@example.com',
            'password' => 'password123',
        ], ['REMOTE_ADDR' => '192.168.1.200']);

        $response->assertRedirect('/home');
        $this->assertAuthenticated();
    }

    public function test_login_is_unrestricted_when_the_site_has_no_ip_configured()
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id, 'ip_address' => null]);
        $this->createTestUser([
            'email' => 'team@example.com',
            'password' => Hash::make('password123'),
            'site_id' => $site->id,
        ]);

        $response = $this->post('/login', [
            'email' => 'team@example.com',
            'password' => 'password123',
        ], ['REMOTE_ADDR' => '1.2.3.4']);

        $response->assertRedirect('/home');
        $this->assertAuthenticated();
    }

    public function test_login_is_unrestricted_for_a_user_with_no_site_at_all()
    {
        $this->createTestUser([
            'email' => 'team@example.com',
            'password' => Hash::make('password123'),
            'site_id' => null,
        ]);

        $response = $this->post('/login', [
            'email' => 'team@example.com',
            'password' => 'password123',
        ], ['REMOTE_ADDR' => '1.2.3.4']);

        $response->assertRedirect('/home');
        $this->assertAuthenticated();
    }

    /**
     * A blocked login previously crashed with a 500 (FK violation on
     * contest_logs.contest_id, which is NOT NULL) whenever the user's own
     * contest_id was null -- a state the schema explicitly permits.
     */
    public function test_blocked_login_does_not_crash_when_the_user_has_no_contest_id()
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id, 'ip_address' => '203.0.113.5']);
        $this->createTestUser([
            'email' => 'team@example.com',
            'password' => Hash::make('password123'),
            'site_id' => $site->id,
            'contest_id' => null,
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'team@example.com',
            'password' => 'password123',
        ], ['REMOTE_ADDR' => '198.51.100.9']);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('contest_logs', [
            'contest_id' => $contest->id,
            'type' => 'warning',
        ]);
    }

    /**
     * Laravel's remember-me cookie re-authenticates on later requests
     * without ever going through this route again -- a team that logs in
     * once from the allowed network with "remember me" checked, then
     * leaves with the device, would otherwise bypass isIpAllowed() forever
     * after. Accounts tied to an IP-restricted site never get a
     * persistent session.
     */
    public function test_remember_me_is_ignored_for_a_user_at_an_ip_restricted_site()
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id, 'ip_address' => '203.0.113.5']);
        $this->createTestUser([
            'email' => 'team@example.com',
            'password' => Hash::make('password123'),
            'site_id' => $site->id,
        ]);

        $response = $this->post('/login', [
            'email' => 'team@example.com',
            'password' => 'password123',
            'remember' => 'on',
        ], ['REMOTE_ADDR' => '203.0.113.5']);

        $response->assertRedirect('/home');
        $this->assertAuthenticated();
        $this->assertFalse(
            collect($response->headers->getCookies())->contains(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_')),
            'no remember-me cookie should be issued for a user at an IP-restricted site'
        );
    }

    public function test_remember_me_still_works_for_a_user_at_a_site_with_no_ip_restriction()
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id, 'ip_address' => null]);
        $this->createTestUser([
            'email' => 'team@example.com',
            'password' => Hash::make('password123'),
            'site_id' => $site->id,
        ]);

        $response = $this->post('/login', [
            'email' => 'team@example.com',
            'password' => 'password123',
            'remember' => 'on',
        ], ['REMOTE_ADDR' => '1.2.3.4']);

        $response->assertRedirect('/home');
        $this->assertTrue(
            collect($response->headers->getCookies())->contains(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_')),
            'remember-me should still work when the site has no IP restriction'
        );
    }
}
