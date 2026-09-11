<?php

namespace Tests\Feature;

use App\Models\AccountActivation;
use App\Models\Contest;
use App\Models\Site;
use Helium\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Issue #47 -- the real, end-to-end activation flow: create a managed
 * account -> visit the one-time activation link -> set a password -> log
 * in. Covers "Criterio de aceite" 5 (expires, single-use, no admin
 * privilege) plus the "genuinely working, not a stub" requirement.
 */
class AccountActivationTest extends TestCase
{
    private function createManagedAccount(): array
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $response = $this->postJson('/api/frontend/managed-accounts', [
            'fullname' => 'Participante Ativacao',
            'username' => 'participante-ativacao',
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ], ['Idempotency-Key' => 'activation-flow-key'])->assertCreated();

        $userId = $response->json('data.id');
        $activationUrl = $response->json('data.activation_url');
        $token = last(explode('/', $activationUrl));

        // The new session created by acting as admin must not leak into the
        // guest activation requests below.
        $this->app['auth']->guard()->logout();
        $this->flushSession();

        return [User::findOrFail($userId), $token, $activationUrl];
    }

    public function test_full_create_activate_set_password_login_flow_works_end_to_end(): void
    {
        [$user, $token, $activationUrl] = $this->createManagedAccount();

        $this->assertFalse($user->is_enabled);
        $activation = AccountActivation::where('user_id', $user->user_id)->firstOrFail();
        $this->assertNull($activation->used_at);
        // Only the hash is ever persisted -- the raw token never appears in
        // storage as-is.
        $this->assertSame(hash('sha256', $token), $activation->token_hash);
        $this->assertNotSame($token, $activation->token_hash);

        $this->get($activationUrl)->assertOk()->assertDontSee('inválido', false);

        $this->post($activationUrl, [
            'password' => 'nova-senha-forte',
            'password_confirmation' => 'nova-senha-forte',
        ])->assertRedirect('/home');

        $user->refresh();
        $this->assertTrue($user->is_enabled);
        $this->assertTrue(Hash::check('nova-senha-forte', $user->password));
        $this->assertAuthenticatedAs($user);

        $activation->refresh();
        $this->assertNotNull($activation->used_at);
    }

    public function test_activation_does_not_change_role_or_contest_and_grants_no_admin_privilege(): void
    {
        [$user, , $activationUrl] = $this->createManagedAccount();
        $contestId = $user->contest_id;
        $siteId = $user->site_id;

        $this->post($activationUrl, [
            'password' => 'nova-senha-forte',
            'password_confirmation' => 'nova-senha-forte',
        ])->assertRedirect('/home');

        $user->refresh();
        $this->assertSame(User::TYPE_TEAM, $user->user_type);
        $this->assertSame($contestId, $user->contest_id);
        $this->assertSame($siteId, $user->site_id);
        $this->assertFalse($user->isAdmin());
    }

    public function test_token_is_single_use(): void
    {
        [, , $activationUrl] = $this->createManagedAccount();

        $this->post($activationUrl, [
            'password' => 'primeira-senha',
            'password_confirmation' => 'primeira-senha',
        ])->assertRedirect('/home');

        $this->app['auth']->guard()->logout();
        $this->flushSession();

        $second = $this->post($activationUrl, [
            'password' => 'segunda-senha',
            'password_confirmation' => 'segunda-senha',
        ]);
        $second->assertRedirect();
        $second->assertSessionHasErrors('token');
    }

    public function test_expired_token_is_rejected(): void
    {
        [$user, $token, $activationUrl] = $this->createManagedAccount();
        AccountActivation::where('user_id', $user->user_id)->update(['expires_at' => now()->subMinute()]);

        $this->get($activationUrl)->assertOk()->assertSee('inválido', false);

        $response = $this->post($activationUrl, [
            'password' => 'qualquer-senha',
            'password_confirmation' => 'qualquer-senha',
        ]);
        $response->assertSessionHasErrors('token');

        $user->refresh();
        $this->assertFalse($user->is_enabled);
    }

    public function test_unknown_token_shows_invalid_without_error(): void
    {
        $this->get('/activate/not-a-real-token')->assertOk()->assertSee('inválido', false);
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        [, , $activationUrl] = $this->createManagedAccount();

        $response = $this->post($activationUrl, [
            'password' => 'senha-um-aqui',
            'password_confirmation' => 'senha-diferente',
        ]);

        $response->assertSessionHasErrors('password');
    }
}
