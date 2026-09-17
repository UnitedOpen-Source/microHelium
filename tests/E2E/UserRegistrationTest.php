<?php

namespace Tests\E2E;

use Tests\TestCase;
use Helium\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

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
     * @return void
     */
    #[Test]
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
     * @return void
     */
    #[Test]
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

        // Issue #275 -- o cadastro leva de volta ao login, e nao a home.
        // A conta nasce desabilitada e sem sessao; entrar depende de a
        // organizacao liberar.
        $response->assertStatus(200);
        $response->assertViewIs('auth.login');
        $response->assertSee('liberada pela organizacao');

        // Verify user was created in database
        $this->assertDatabaseHas('users', [
            'fullname' => 'Maria Silva',
            'username' => 'mariasilva',
            'email' => 'maria.silva@example.com',
            'user_type' => 'team',
            // Issue #275 -- era `true`. Uma conta que nasce ativa sem
            // convite e sem ativacao contradiz as contas gerenciadas do
            // #47, que nascem desabilitadas de proposito.
            'is_enabled' => false,
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
     * @return void
     */
    #[Test]
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
        // Issue #275 -- de volta ao login, com a mensagem de espera.
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('success');
    }

    /**
     * Test that user is automatically logged in after registration
     *
     * @return void
     */
    #[Test]
    public function user_is_not_logged_in_after_registration()
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

        $this->post('/register', $userData);

        // Issue #275 -- este teste afirmava o DEFEITO.
        //
        // Ele exigia `assertAuthenticated()`, fixando que o auto-cadastro
        // entrega sessao na hora -- que e precisamente o caminho para obter
        // sessao autenticada sem decisao de ninguem da organizacao. Um teste
        // que protege o defeito e pior que nenhum, porque a proxima pessoa o
        // le como requisito.
        $this->assertGuest();
        $this->assertNull(auth()->user(), 'o cadastro autenticou o visitante');

        // A conta existe, e espera liberacao.
        $criada = User::where('email', 'ana.costa@example.com')->first();
        $this->assertNotNull($criada);
        $this->assertEquals('Ana Costa', $criada->fullname);
        $this->assertFalse((bool) $criada->is_enabled, 'a conta nasceu habilitada');
    }

    /**
     * Test validation errors are shown for invalid input - missing fields
     *
     * @return void
     */
    #[Test]
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
     * @return void
     */
    #[Test]
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
     * @return void
     */
    #[Test]
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
     * @return void
     */
    #[Test]
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
     * @return void
     */
    #[Test]
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
     * @return void
     */
    #[Test]
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
     * @return void
     */
    #[Test]
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

        // Issue #275 -- termina no login, nao na home.
        $response->assertStatus(200);
        $response->assertViewIs('auth.login');

        $this->assertGuest();

        $user = User::where('email', 'joao.pedro@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('João Pedro', $user->fullname);
        $this->assertEquals('joaopedro', $user->username);
        $this->assertEquals('team', $user->user_type);
        $this->assertFalse((bool) $user->is_enabled);
    }

    /**
     * Test that registered user can access protected routes
     *
     * @return void
     */
    #[Test]
    public function a_self_registered_user_cannot_reach_protected_routes_before_release()
    {
        $userData = [
            'fullname' => 'Protected User',
            'username' => 'protecteduser',
            'email' => 'protected@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $this->post('/register', $userData);

        // Issue #275 -- o inverso do que este teste afirmava.
        //
        // Ele exigia que a conta recem-cadastrada alcancasse /home,
        // /submissions e /clarifications na hora -- ou seja, fixava que
        // qualquer visitante obtem acesso as telas de participante sem
        // ninguem da organizacao ter decidido nada.
        $this->assertGuest();

        foreach (['/home', '/submissions', '/clarifications'] as $rota) {
            $this->get($rota)->assertRedirect('/login');
        }

        // E liberada, alcanca -- senao o bloco acima valeria por "estas
        // rotas nao abrem para ninguem".
        $liberada = User::where('email', 'protected@example.com')->first();
        $liberada->update(['is_enabled' => true]);

        $this->actingAs($liberada)->get('/home')->assertSuccessful();
    }

    /**
     * Test registration form handles invalid email format
     *
     * @return void
     */
    #[Test]
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
     * @return void
     */
    #[Test]
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
        // Issue #275 -- era `assertTrue`. Nasce desabilitada.
        $this->assertFalse((bool) $user->is_enabled);
    }

    /**
     * Test that special characters in fullname are accepted
     *
     * @return void
     */
    #[Test]
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

        $response->assertRedirect(route('login'));
        $this->assertDatabaseHas('users', [
            'fullname' => 'José María O\'Connor-Smith',
            'email' => 'jose@example.com',
        ]);
    }

    /**
     * Test that very long passwords are accepted (up to reasonable limit)
     *
     * @return void
     */
    #[Test]
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

        $response->assertRedirect(route('login'));
        $this->assertGuest();

        // Verify password is hashed correctly
        $user = DB::table('users')
            ->where('email', 'longpass@example.com')
            ->first();

        $this->assertTrue(Hash::check($longPassword, $user->password));
    }

    /**
     * Test edge case: maximum length for fields
     *
     * @return void
     */
    #[Test]
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
