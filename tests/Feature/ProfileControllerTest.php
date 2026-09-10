<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileControllerTest extends TestCase
{
    public function test_guest_is_redirected_to_login()
    {
        $response = $this->get('/profile');

        $response->assertRedirect('/login');
    }

    public function test_any_authenticated_role_can_view_their_profile()
    {
        $user = $this->createTestUser(['user_type' => 'judge', 'fullname' => 'Judge Judy']);

        $response = $this->actingAs($user)->get('/profile');

        $response->assertStatus(200);
        $response->assertViewIs('profile.edit');
        // The fullname only appears inside the form's value="" attribute,
        // not as visible text -- assertSeeText would pass regardless
        // because the shared navbar also prints it. Check the raw HTML
        // (assertSee with $escaped=false) so this actually exercises the
        // form field being populated from $user.
        $response->assertSee('value="Judge Judy"', false);
    }

    public function test_stale_current_password_does_not_block_an_unrelated_edit()
    {
        $user = $this->createTestUser(['password' => Hash::make('old-password')]);

        // Simulates a password manager autofilling "Senha atual" with a
        // stale/wrong value even though the user isn't changing their
        // password (the "Nova senha" fields are left blank).
        $response = $this->actingAs($user)->put('/profile', [
            'fullname' => 'Fixed Typo',
            'email' => $user->email,
            'current_password' => 'stale-autofilled-value',
        ]);

        $response->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Fixed Typo', $user->fullname);
    }

    public function test_user_can_update_fullname_and_email()
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user)->put('/profile', [
            'fullname' => 'New Name',
            'email' => 'new-email@example.com',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('New Name', $user->fullname);
        $this->assertSame('new-email@example.com', $user->email);
    }

    public function test_user_can_change_password_with_correct_current_password()
    {
        $user = $this->createTestUser(['password' => Hash::make('old-password')]);

        $response = $this->actingAs($user)->put('/profile', [
            'fullname' => $user->fullname,
            'email' => $user->email,
            'current_password' => 'old-password',
            'password' => 'new-strong-password',
            'password_confirmation' => 'new-strong-password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertTrue(Hash::check('new-strong-password', $user->password));
    }

    public function test_password_change_is_rejected_with_wrong_current_password()
    {
        $user = $this->createTestUser(['password' => Hash::make('old-password')]);

        $response = $this->actingAs($user)->put('/profile', [
            'fullname' => $user->fullname,
            'email' => $user->email,
            'current_password' => 'totally-wrong',
            'password' => 'new-strong-password',
            'password_confirmation' => 'new-strong-password',
        ]);

        $response->assertSessionHasErrors('current_password');

        $user->refresh();
        $this->assertTrue(Hash::check('old-password', $user->password));
    }

    public function test_email_must_be_unique_across_other_users()
    {
        $other = $this->createTestUser(['email' => 'taken@example.com']);
        $user = $this->createTestUser();

        $response = $this->actingAs($user)->put('/profile', [
            'fullname' => $user->fullname,
            'email' => 'taken@example.com',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_user_can_keep_their_own_email_unchanged()
    {
        $user = $this->createTestUser(['email' => 'me@example.com']);

        $response = $this->actingAs($user)->put('/profile', [
            'fullname' => $user->fullname,
            'email' => 'me@example.com',
        ]);

        $response->assertSessionHasNoErrors();
    }
}
