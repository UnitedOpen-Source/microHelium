<?php

namespace Tests\Feature\Api;

use App\Models\Contest;
use App\Models\Site;
use Helium\User;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Issue #159 -- the authenticated API, exercised the way a client reaches
 * it.
 *
 * Every other test of routes/api.php authenticates with
 * Sanctum::actingAs(), which puts the user straight into the guard and
 * issues no token at all. That is why a broken API passed a full suite:
 * `personal_access_tokens` did not exist, `$user->createToken()` threw
 * "no such table", and nothing in the suite ever called it. The measurement
 * confirmed the assumption instead of confronting it.
 *
 * So nothing in this file may use Sanctum::actingAs(). Every request here
 * carries a real Authorization header with a real plain-text token that
 * came out of a real POST /api/tokens, because that is the only path that
 * proves the table, the route, the guard and the hashing all line up.
 */
class ApiTokenIssuanceTest extends TestCase
{
    /**
     * The missing artifact itself, named. If this fails, the migration was
     * dropped and every other test in this file is about to fail for a
     * reason that will look like something else.
     */
    public function test_the_sanctum_token_table_is_migrated(): void
    {
        $this->assertTrue(
            Schema::hasTable('personal_access_tokens'),
            'Sanctum 4 does not load its own migrations. Without the published migration, createToken() throws '
            .'"no such table" and no client can authenticate against /api/* at all (#159).'
        );
    }

    public function test_a_real_bearer_token_reaches_the_api(): void
    {
        $user = $this->createTestUser();

        $issued = $this->postJson('/api/tokens', [
            'login' => $user->email,
            'password' => 'password',
            'device_name' => 'notebook-da-equipe',
        ])->assertStatus(201);

        $plainText = $issued->json('token');
        $this->assertIsString($plainText);
        $this->assertNotSame('', $plainText);

        // The token is what carries the identity -- no session, no cookie,
        // nothing but the header.
        $this->asToken($plainText)
            ->getJson('/api/user')
            ->assertStatus(200)
            ->assertJsonPath('user_id', $user->user_id);
    }

    /**
     * Same contract as judgehost:create (#112): the plain text exists for
     * the length of one response and the database keeps only a digest, so
     * a leaked dump is not a set of working credentials and nobody --
     * staff included -- can read a token back out.
     */
    public function test_the_database_keeps_only_a_digest(): void
    {
        $user = $this->createTestUser();

        $plainText = $this->postJson('/api/tokens', [
            'login' => $user->email,
            'password' => 'password',
            'device_name' => 'cli',
        ])->json('token');

        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $plainText]);

        [, $secret] = explode('|', $plainText, 2);

        $this->assertDatabaseHas('personal_access_tokens', [
            'token' => hash('sha256', $secret),
            'tokenable_id' => $user->user_id,
        ]);
    }

    /**
     * The README's curl example posts a form, not JSON. It is the first
     * thing anyone writing a client will copy, so it is pinned rather than
     * assumed to work.
     */
    public function test_a_form_encoded_post_works_too(): void
    {
        $user = $this->createTestUser();

        $this->post('/api/tokens', [
            'login' => $user->username,
            'password' => 'password',
            'device_name' => 'notebook-da-equipe',
        ], ['Accept' => 'application/json'])->assertStatus(201)->assertJsonStructure(['token']);
    }

    public function test_a_username_is_accepted_as_well_as_an_email(): void
    {
        $user = $this->createTestUser();

        $this->postJson('/api/tokens', [
            'login' => $user->username,
            'password' => 'password',
            'device_name' => 'cli',
        ])->assertStatus(201);
    }

    /**
     * A participant list is public by nature, so this route must not tell
     * an attacker which of the names on it are accounts: a wrong password
     * and an account that does not exist have to come back identical.
     */
    public function test_a_wrong_password_and_an_unknown_account_are_indistinguishable(): void
    {
        $user = $this->createTestUser();

        $wrongPassword = $this->postJson('/api/tokens', [
            'login' => $user->email,
            'password' => 'nao-e-essa',
            'device_name' => 'cli',
        ])->assertStatus(422);

        $noSuchUser = $this->postJson('/api/tokens', [
            'login' => 'ninguem@example.com',
            'password' => 'nao-e-essa',
            'device_name' => 'cli',
        ])->assertStatus(422);

        $this->assertSame($wrongPassword->json(), $noSuchUser->json());
        $this->assertSame(0, PersonalAccessToken::count());
    }

    /**
     * Issue #47 leaves a managed account disabled until it is activated.
     * auth()->attempt() does not consult is_enabled, so an account that
     * cannot log in on the web would otherwise have been able to take a
     * token here and use the API with it.
     */
    public function test_a_disabled_account_gets_no_token(): void
    {
        $user = $this->createTestUser(['is_enabled' => false]);

        $this->postJson('/api/tokens', [
            'login' => $user->email,
            'password' => 'password',
            'device_name' => 'cli',
        ])->assertStatus(403);

        $this->assertSame(0, PersonalAccessToken::count());
    }

    /**
     * Issue #50 pins an account's site to an address range and the web
     * login enforces it. A token route that did not would be the way
     * around it: log in from anywhere, get a bearer token, use the API from
     * anywhere.
     */
    public function test_a_site_address_restriction_applies_to_token_issuing(): void
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create([
            'contest_id' => $contest->id,
            'ip_address' => '10.0.0.0/24',
        ]);

        $user = $this->createTestUser(['contest_id' => $contest->id, 'site_id' => $site->id]);

        $this->postJson('/api/tokens', [
            'login' => $user->email,
            'password' => 'password',
            'device_name' => 'cli',
        ], ['REMOTE_ADDR' => '203.0.113.9'])->assertStatus(403);

        $this->assertSame(0, PersonalAccessToken::count());

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
            ->postJson('/api/tokens', [
                'login' => $user->email,
                'password' => 'password',
                'device_name' => 'cli',
            ])->assertStatus(201);
    }

    /**
     * Sanctum's own default is a token that never expires. A contest
     * account outlives the event by nothing, and the token gets typed into
     * a machine in a lab the next contest also uses.
     */
    public function test_a_token_stops_working_once_it_expires(): void
    {
        $user = $this->createTestUser();

        $issued = $this->postJson('/api/tokens', [
            'login' => $user->email,
            'password' => 'password',
            'device_name' => 'cli',
        ])->assertStatus(201);

        $this->assertNotNull($issued->json('expires_at'), 'An issued token must say when it dies.');

        $token = $issued->json('token');

        $this->asToken($token)
            ->getJson('/api/user')
            ->assertStatus(200);

        $this->travel((int) config('sanctum.expiration') + 1)->minutes();

        $this->asToken($token)
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    public function test_a_revoked_token_stops_working(): void
    {
        $token = $this->tokenFor($this->createTestUser());

        $this->asToken($token)
            ->deleteJson('/api/tokens/current')
            ->assertStatus(200);

        $this->asToken($token)
            ->getJson('/api/user')
            ->assertStatus(401);
    }

    public function test_a_user_lists_only_their_own_tokens_and_never_the_secret(): void
    {
        $mine = $this->createTestUser();
        $theirs = $this->createTestUser();

        $token = $this->tokenFor($mine, 'meu-notebook');
        $this->tokenFor($theirs, 'notebook-de-outra-equipe');

        $listed = $this->asToken($token)
            ->getJson('/api/tokens')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $listed);
        $this->assertSame('meu-notebook', $listed[0]['name']);
        $this->assertTrue($listed[0]['current']);
        $this->assertArrayNotHasKey('token', $listed[0]);
        $this->assertStringNotContainsString(explode('|', $token, 2)[1], json_encode($listed));
    }

    /**
     * The boundary these routes actually have. There is no contest
     * dimension to a personal access token, which is why
     * ApiRouteAuthorizationTest lists them in NO_CONTEST_DIMENSION -- this
     * is the assertion that stands in its place.
     */
    public function test_a_user_cannot_revoke_another_users_token(): void
    {
        $mine = $this->createTestUser();
        $theirs = $this->createTestUser();

        $mineToken = $this->tokenFor($mine);
        $theirsToken = $this->tokenFor($theirs);

        $victim = PersonalAccessToken::findToken($theirsToken);

        $this->asToken($mineToken)
            ->deleteJson('/api/tokens/'.$victim->getKey())
            ->assertStatus(404);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $victim->getKey()]);

        // And it is still a working credential, not merely a surviving row.
        $this->asToken($theirsToken)
            ->getJson('/api/user')
            ->assertStatus(200);
    }

    /**
     * Issue #134 split routes/api.php by audience and every test of that
     * split authenticates with Sanctum::actingAs(). This is the same claim
     * made against the real mechanism: a team's bearer token is refused by
     * the staff surface, and reaches the competitor surface.
     */
    public function test_a_real_team_token_is_still_only_a_team_token(): void
    {
        $contest = Contest::factory()->create();
        $team = $this->createTestUser(['user_type' => 'team', 'contest_id' => $contest->id]);

        $token = $this->tokenFor($team);

        $this->asToken($token)
            ->deleteJson('/api/contests/'.$contest->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('contests', ['id' => $contest->id]);

        $this->asToken($token)
            ->getJson('/api/contests')
            ->assertStatus(200);
    }

    /**
     * An unthrottled credentials endpoint is a password-guessing appliance.
     * Same 5/minute as the web login in routes/web.php.
     */
    public function test_issuing_is_throttled(): void
    {
        $user = $this->createTestUser();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/tokens', [
                'login' => $user->email,
                'password' => 'errada',
                'device_name' => 'cli',
            ])->assertStatus(422);
        }

        $this->postJson('/api/tokens', [
            'login' => $user->email,
            'password' => 'password',
            'device_name' => 'cli',
        ])->assertStatus(429);
    }

    /**
     * A request carrying $token and nothing else.
     *
     * forgetGuards() is the load-bearing part, and it is here rather than
     * inline because leaving it out makes this whole file lie. Laravel
     * boots the application once per test method, so Auth's guard instances
     * -- Sanctum's RequestGuard included -- survive from one request to the
     * next, and RequestGuard caches the user it resolved the first time.
     * Without this line the second request in a test is answered from that
     * cache and never looks at the header at all: revoking a token and then
     * using it came back 200, and an expired token came back 200, both for
     * a reason that has nothing to do with the code under test. Dropping
     * the guards makes each request resolve its own credential, which is
     * what a separate HTTP request does in production.
     */
    private function asToken(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    /**
     * A plain-text token for $user, taken through the real route.
     */
    private function tokenFor(User $user, string $device = 'cli'): string
    {
        return $this->postJson('/api/tokens', [
            'login' => $user->email,
            'password' => 'password',
            'device_name' => $device,
        ])->assertStatus(201)->json('token');
    }
}
