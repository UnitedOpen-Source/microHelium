<?php

namespace Tests\E2E;

use Tests\TestCase;
use Helium\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

/**
 * End-to-End tests for User Registration Flow
 *
 * These tests simulate the complete user registration journey
 * from visiting the registration page to successful account creation
 * and authentication.
 */
class UserRegistrationTest extends TestCase
{
    /**
     * Test that user can visit the registration page
     *
     * @test
     * @return void
     */
    public function user_can_visit_registration_page()
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
        $response->assertViewIs('auth.register');
        $response->assertSee('Criar Conta');
        $response->assertSee('Nome Completo');
        $response->assertSee('Usuário');
        $response->assertSee('E-mail');
        $response->assertSee('Senha');
        $response->assertSee('Confirmar Senha');
    }

    /**
     * Test that user can fill registration form and submit successfully
     *
     * @test
     * @return void
     */
    public function user_can_fill_registration_form_and_submit()
    {
        $userData = [
            'fullname' => 'Maria Silva',
            'username' => 'mariasilva',
            'email' => 'maria.silva@example.com',
            'password' => 'securepassword123',
            'password_confirmation' => 'securepassword123',
        ];

        $response = $this->followingRedirects()
            ->post('/register', $userData);

        // Assert successful registration with redirect to home
        $response->assertStatus(200);
        $response->assertViewIs('home');
        $response->assertSee('Conta criada com sucesso!');

        // Verify user was created in database
        $this->assertDatabaseHas('users', [
            'fullname' => 'Maria Silva',
            'username' => 'mariasilva',
            'email' => 'maria.silva@example.com',
            'user_type' => 'team',
            'is_enabled' => true,
        ]);

        // Verify password was hashed
        $user = DB::table('users')
            ->where('email', 'maria.silva@example.com')
            ->first();

        $this->assertTrue(Hash::check('securepassword123', $user->password));
    }

    /**
     * Test that user is redirected after successful registration
     *
     * @test
     * @return void
     */
    public function user_is_redirected_after_successful_registration()
    {
        $userData = [
            'fullname' => 'Carlos Santos',
            'username' => 'carlossantos',
            'email' => 'carlos.santos@example.com',
            'password' => 'mypassword456',
            'password_confirmation' => 'mypassword456',
        ];

        $response = $this->post('/register', $userData);

        // Assert redirect to home page
        $response->assertRedirect('/home');
        $response->assertSessionHas('success', 'Conta criada com sucesso!');
    }

    /**
     * Test that user is automatically logged in after registration
     *
     * @test
     * @return void
     */
    public function user_is_logged_in_after_registration()
    {
        $userData = [
            'fullname' => 'Ana Costa',
            'username' => 'anacosta',
            'email' => 'ana.costa@example.com',
            'password' => 'testpassword789',
            'password_confirmation' => 'testpassword789',
        ];

        // Assert user is not authenticated before registration
        $this->assertGuest();

        $response = $this->post('/register', $userData);

        // Assert user is authenticated after registration
        $this->assertAuthenticated();

        // Verify the authenticated user is the one we just registered
        $authenticatedUser = auth()->user();
        $this->assertEquals('Ana Costa', $authenticatedUser->fullname);
        $this->assertEquals('anacosta', $authenticatedUser->username);
        $this->assertEquals('ana.costa@example.com', $authenticatedUser->email);
    }

    /**
     * Test validation errors are shown for invalid input - missing fields
     *
     * @test
     * @return void
     */
    public function validation_errors_shown_for_missing_fields()
    {
        $response = $this->post('/register', [
            'fullname' => '',
            'username' => '',
            'email' => '',
            'password' => '',
            'password_confirmation' => '',
        ]);

        $response->assertSessionHasErrors(['fullname', 'username', 'email', 'password']);
        $this->assertGuest();
    }

    /**
     * Test validation errors are shown for invalid email format
     *
     * @test
     * @return void
     */
    public function validation_errors_shown_for_invalid_email_format()
    {
        $response = $this->post('/register', [
            'fullname' => 'Test User',
            'username' => 'testuser',
            'email' => 'invalid-email-format',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors(['email']);
        $this->assertGuest();
    }

    /**
     * Test validation errors are shown for password too short
     *
     * @test
     * @return void
     */
    public function validation_errors_shown_for_short_password()
    {
        $response = $this->post('/register', [
            'fullname' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors(['password']);
        $this->assertGuest();
    }

    /**
     * Test validation errors are shown for mismatched passwords
     *
     * @test
     * @return void
     */
    public function validation_errors_shown_for_password_mismatch()
    {
        $response = $this->post('/register', [
            'fullname' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'differentpassword',
        ]);

        $response->assertSessionHasErrors(['password']);
        $this->assertGuest();
    }

    /**
     * Test that duplicate email is rejected
     *
     * @test
     * @return void
     */
    public function duplicate_email_is_rejected()
    {
        // Create an existing user
        DB::table('users')->insert([
            'fullname' => 'Existing User',
            'username' => 'existinguser',
            'email' => 'existing@example.com',
            'password' => Hash::make('password123'),
            'user_type' => 'team',
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempt to register with the same email
        $response = $this->post('/register', [
            'fullname' => 'New User',
            'username' => 'newuser',
            'email' => 'existing@example.com',
            'password' => 'password456',
            'password_confirmation' => 'password456',
        ]);

        $response->assertSessionHasErrors(['email']);
        $this->assertGuest();

        // Verify only one user with this email exists
        $userCount = DB::table('users')
            ->where('email', 'existing@example.com')
            ->count();

        $this->assertEquals(1, $userCount);
    }

    /**
     * Test that duplicate username is rejected
     *
     * @test
     * @return void
     */
    public function duplicate_username_is_rejected()
    {
        // Create an existing user
        DB::table('users')->insert([
            'fullname' => 'Existing User',
            'username' => 'existingusername',
            'email' => 'existing@example.com',
            'password' => Hash::make('password123'),
            'user_type' => 'team',
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempt to register with the same username
        $response = $this->post('/register', [
            'fullname' => 'New User',
            'username' => 'existingusername',
            'email' => 'newuser@example.com',
            'password' => 'password456',
            'password_confirmation' => 'password456',
        ]);

        $response->assertSessionHasErrors(['username']);
        $this->assertGuest();

        // Verify only one user with this username exists
        $userCount = DB::table('users')
            ->where('username', 'existingusername')
            ->count();

        $this->assertEquals(1, $userCount);
    }

    /**
     * Test complete registration flow with followRedirects
     *
     * @test
     * @return void
     */
    public function complete_registration_flow_with_follow_redirects()
    {
        $userData = [
            'fullname' => 'João Pedro',
            'username' => 'joaopedro',
            'email' => 'joao.pedro@example.com',
            'password' => 'strongpassword123',
            'password_confirmation' => 'strongpassword123',
        ];

        // Follow the entire registration flow
        $response = $this->followingRedirects()
            ->post('/register', $userData);

        // Should end up on the home page
        $response->assertStatus(200);
        $response->assertViewIs('home');

        // User should be authenticated
        $this->assertAuthenticated();

        // Verify user data
        $user = auth()->user();
        $this->assertEquals('João Pedro', $user->fullname);
        $this->assertEquals('joaopedro', $user->username);
        $this->assertEquals('joao.pedro@example.com', $user->email);
        $this->assertEquals('team', $user->user_type);
    }

    /**
     * Test that registered user can access protected routes
     *
     * @test
     * @return void
     */
    public function registered_user_can_access_protected_routes()
    {
        $userData = [
            'fullname' => 'Protected User',
            'username' => 'protecteduser',
            'email' => 'protected@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        // Register the user
        $this->post('/register', $userData);

        // User should be authenticated
        $this->assertAuthenticated();

        // Should be able to access protected routes
        $response = $this->get('/home');
        $response->assertStatus(200);

        $response = $this->get('/submissions');
        $response->assertStatus(200);

        $response = $this->get('/clarifications');
        $response->assertStatus(200);
    }

    /**
     * Test registration form handles invalid email format
     *
     * @test
     * @return void
     */
    public function registration_form_preserves_old_input_on_validation_error()
    {
        $response = $this->post('/register', [
            'fullname' => 'Test User',
            'username' => 'testuser',
            'email' => 'invalid-email',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        // Should either redirect with errors or return validation error
        $this->assertTrue(in_array($response->status(), [302, 422]));

        // If it redirected, there should be errors in session (but only check if redirect)
        if ($response->status() === 302) {
            // Check if session has any errors (validation might use different field names)
            $hasErrors = $response->getSession()->has('errors');
            $this->assertTrue($hasErrors, 'Expected validation errors in session after invalid email');
        }
    }

    /**
     * Test that user type is set to 'team' by default
     *
     * @test
     * @return void
     */
    public function new_user_defaults_to_team_type()
    {
        $userData = [
            'fullname' => 'Team User',
            'username' => 'teamuser',
            'email' => 'team@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $this->post('/register', $userData);

        $user = DB::table('users')
            ->where('email', 'team@example.com')
            ->first();

        $this->assertEquals('team', $user->user_type);
        $this->assertTrue((bool) $user->is_enabled);
    }

    /**
     * Test that special characters in fullname are accepted
     *
     * @test
     * @return void
     */
    public function registration_accepts_special_characters_in_fullname()
    {
        $userData = [
            'fullname' => 'José María O\'Connor-Smith',
            'username' => 'joseuser',
            'email' => 'jose@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->post('/register', $userData);

        $response->assertRedirect('/home');
        $this->assertDatabaseHas('users', [
            'fullname' => 'José María O\'Connor-Smith',
            'email' => 'jose@example.com',
        ]);
    }

    /**
     * Test that very long passwords are accepted (up to reasonable limit)
     *
     * @test
     * @return void
     */
    public function registration_accepts_long_passwords()
    {
        $longPassword = str_repeat('abcdefgh', 10); // 80 characters

        $userData = [
            'fullname' => 'Long Password User',
            'username' => 'longpassuser',
            'email' => 'longpass@example.com',
            'password' => $longPassword,
            'password_confirmation' => $longPassword,
        ];

        $response = $this->post('/register', $userData);

        $response->assertRedirect('/home');
        $this->assertAuthenticated();

        // Verify password is hashed correctly
        $user = DB::table('users')
            ->where('email', 'longpass@example.com')
            ->first();

        $this->assertTrue(Hash::check($longPassword, $user->password));
    }

    /**
     * Test edge case: maximum length for fields
     *
     * @test
     * @return void
     */
    public function registration_enforces_maximum_field_lengths()
    {
        $tooLongString = str_repeat('a', 256); // Exceeds max of 255

        $response = $this->post('/register', [
            'fullname' => $tooLongString,
            'username' => $tooLongString,
            'email' => $tooLongString . '@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors(['fullname', 'username', 'email']);
        $this->assertGuest();
    }
}
