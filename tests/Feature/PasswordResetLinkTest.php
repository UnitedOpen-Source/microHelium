<?php

namespace Tests\Feature;

use App\Models\AccountActivation;
use Helium\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Issue #183 -- recovering an account without anyone else learning the
 * password.
 *
 * The admin edit screen could already set somebody's password directly,
 * and that is the problem this closes: whoever types it then knows it, and
 * has to say it across a contest hall. An organiser now hands over a
 * one-time link and the account holder picks their own.
 *
 * Built on #47's activation machinery rather than a second scheme, because
 * a reset and an activation are the same act -- prove you hold this token,
 * then choose a password -- and that one is already hardened: atomic
 * claim, single use, self-expiring. No e-mail anywhere: this application
 * has never sent one, and a reset that needs SMTP on a LAN contest host is
 * a reset that fails when it is needed.
 */
class PasswordResetLinkTest extends TestCase
{
    private function admin(): User
    {
        return $this->createTestUser(['user_type' => 'admin']);
    }

    public function test_an_organiser_hands_over_a_link_and_the_person_sets_their_own_password(): void
    {
        $team = $this->createTestUser(['user_type' => 'team']);

        $link = $this->actingAs($this->admin())
            ->post(route('backend.users.reset-link', $team->user_id))
            ->assertRedirect()
            ->getSession()
            ->get('reset_link');

        $this->assertIsArray($link);
        $this->assertStringStartsWith('/activate/', $link['url']);

        // Only the digest is kept: the organiser's screen is the one and
        // only place this token exists.
        $raw = substr($link['url'], strlen('/activate/'));
        $this->assertDatabaseHas('account_activations', [
            'user_id' => $team->user_id,
            'token_hash' => hash('sha256', $raw),
        ]);
        $this->assertDatabaseMissing('account_activations', ['token_hash' => $raw]);

        // And it works: the person opens it and chooses a password nobody
        // else typed.
        $this->post($link['url'], [
            'password' => 'senha-escolhida-por-mim',
            'password_confirmation' => 'senha-escolhida-por-mim',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('senha-escolhida-por-mim', $team->fresh()->password));
    }

    public function test_the_link_works_once(): void
    {
        $team = $this->createTestUser(['user_type' => 'team']);

        $url = $this->actingAs($this->admin())
            ->post(route('backend.users.reset-link', $team->user_id))
            ->getSession()->get('reset_link')['url'];

        $this->post($url, ['password' => 'primeira-senha-boa', 'password_confirmation' => 'primeira-senha-boa']);
        $this->assertTrue(Hash::check('primeira-senha-boa', $team->fresh()->password));

        // A second use must not work -- a link handed over on paper outlives
        // the moment it was needed.
        $this->post($url, ['password' => 'segunda-senha-boa', 'password_confirmation' => 'segunda-senha-boa']);

        $this->assertTrue(
            Hash::check('primeira-senha-boa', $team->fresh()->password),
            'the link was reusable, so anyone who kept the paper could take the account later'
        );
    }

    public function test_an_expired_link_is_refused(): void
    {
        $team = $this->createTestUser(['user_type' => 'team']);

        $url = $this->actingAs($this->admin())
            ->post(route('backend.users.reset-link', $team->user_id))
            ->getSession()->get('reset_link')['url'];

        AccountActivation::where('user_id', $team->user_id)->update(['expires_at' => now()->subMinute()]);

        $this->post($url, ['password' => 'tarde-demais-agora', 'password_confirmation' => 'tarde-demais-agora']);

        $this->assertFalse(Hash::check('tarde-demais-agora', $team->fresh()->password));
    }

    public function test_only_an_admin_can_mint_one(): void
    {
        $team = $this->createTestUser(['user_type' => 'team']);

        foreach (['team', 'judge', 'staff'] as $type) {
            $this->actingAs($this->createTestUser(['user_type' => $type]))
                ->post(route('backend.users.reset-link', $team->user_id))
                ->assertStatus(403);
        }

        $this->assertSame(0, AccountActivation::count(), 'a link was minted for a caller who may not mint one');
    }

    public function test_a_visitor_who_is_not_logged_in_cannot_mint_one(): void
    {
        $team = $this->createTestUser(['user_type' => 'team']);

        $this->post(route('backend.users.reset-link', $team->user_id))->assertRedirect('/login');

        $this->assertSame(0, AccountActivation::count());
    }
}
