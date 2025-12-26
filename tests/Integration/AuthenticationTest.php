<?php

namespace Tests\Integration;

use Tests\TestCase;
use Helium\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AuthenticationTest extends TestCase
{
    /**
     * Test that the login page is accessible
     *
     * @return void
     */
    public function testLoginPageIsAccessible()
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertViewIs('auth.login');
    }

    /**
     * Test that the registration page is accessible
     *
     * @return void
     */
    public function testRegistrationPageIsAccessible()
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
        $response->assertViewIs('auth.register');
    }

    /**
     * Test that a user can login with valid credentials
     *
     * @return void
     */
    public function testUserCanLoginWithValidCredentials()
    {
        // Create a test user
        $user = $this->createTestUser([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Attempt to login
        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        // Assert the user is redirected to home
        $response->assertRedirect('/home');

        // Assert the user is authenticated
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Test that a user can login with valid credentials and remember me
     *
     * @return void
     */
    public function testUserCanLoginWithRememberMe()
    {
        // Create a test user
        $user = $this->createTestUser([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Attempt to login with remember me
        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
            'remember' => 'on',
        ]);

        // Assert the user is redirected to home
        $response->assertRedirect('/home');

        // Assert the user is authenticated
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Test that a user cannot login with invalid email
     *
     * @return void
     */
    public function testUserCannotLoginWithInvalidEmail()
    {
        // Create a test user
        $this->createTestUser([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Attempt to login with wrong email
        $response = $this->post('/login', [
            'email' => 'wrong@example.com',
            'password' => 'password123',
        ]);

        // Assert the user is redirected back
        $response->assertRedirect('/');

        // Assert there are validation errors
        $response->assertSessionHasErrors(['email']);

        // Assert the user is not authenticated
        $this->assertGuest();
    }

    /**
     * Test that a user cannot login with invalid password
     *
     * @return void
     */
    public function testUserCannotLoginWithInvalidPassword()
    {
        // Create a test user
        $this->createTestUser([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Attempt to login with wrong password
        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        // Assert the user is redirected back
        $response->assertRedirect('/');

        // Assert there are validation errors
        $response->assertSessionHasErrors(['email']);

        // Assert the user is not authenticated
        $this->assertGuest();
    }

    /**
     * Test that a user cannot login with empty credentials
     *
     * @return void
     */
    public function testUserCannotLoginWithEmptyCredentials()
    {
        // Attempt to login with empty credentials
        $response = $this->post('/login', [
            'email' => '',
            'password' => '',
        ]);

        // Assert the user is redirected back
        $response->assertRedirect('/');

        // Assert the user is not authenticated
        $this->assertGuest();
    }

    /**
     * Test that an authenticated user can access protected routes
     *
     * @return void
     */
    public function testAuthenticatedUserCanAccessProtectedRoutes()
    {
        // Create and authenticate a test user
        $user = $this->createTestUser();

        // Access protected routes as authenticated user
        $response = $this->actingAs($user)->get('/submissions');
        $response->assertStatus(200);

        $response = $this->actingAs($user)->get('/clarifications');
        $response->assertStatus(200);

        $response = $this->actingAs($user)->get('/home');
        $response->assertStatus(200);
    }

    /**
     * Test that authenticated user can submit a clarification
     *
     * @return void
     */
    public function testAuthenticatedUserCanSubmitClarification()
    {
        // Create necessary tables data
        DB::table('contests')->insert([
            'name' => 'Test Contest',
            'description' => 'Test',
            'start_time' => now(),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sites')->insert([
            'contest_id' => 1,
            'name' => 'Main Site',
            'is_active' => true,
            'permit_logins' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create and authenticate a test user
        $user = $this->createTestUser();

        // Submit a clarification
        $response = $this->actingAs($user)->post('/clarifications', [
            'question' => 'What is the time limit?',
        ]);

        // Assert redirect with success message
        $response->assertRedirect(route('clarifications'));
        $response->assertSessionHas('success', 'Pergunta enviada com sucesso!');

        // Assert the clarification was created
        $this->assertDatabaseHas('clarifications', [
            'question' => 'What is the time limit?',
            'user_id' => $user->user_id,
            'status' => 'pending',
        ]);
    }

    /**
     * Test that unauthenticated users can access public routes
     *
     * @return void
     */
    public function testUnauthenticatedUserCanAccessPublicRoutes()
    {
        // Test public routes
        $response = $this->get('/');
        $response->assertStatus(200);

        $response = $this->get('/exercises');
        $response->assertStatus(200);

        $response = $this->get('/scoreboard');
        $response->assertStatus(200);

        $response = $this->get('/ajuda');
        $response->assertStatus(200);
    }

    /**
     * Test that an authenticated user can logout
     *
     * @return void
     */
    public function testAuthenticatedUserCanLogout()
    {
        // Create and authenticate a test user
        $user = $this->createTestUser();

        // Login the user
        $this->actingAs($user);
        $this->assertAuthenticatedAs($user);

        // Logout
        $response = $this->post('/logout');

        // Assert redirect to home
        $response->assertRedirect('/');

        // Assert the user is no longer authenticated
        $this->assertGuest();
    }

    /**
     * Test that session is regenerated on login
     *
     * @return void
     */
    public function testSessionIsRegeneratedOnLogin()
    {
        // Create a test user
        $user = $this->createTestUser([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Start a session
        $this->get('/login');
        $oldSessionId = session()->getId();

        // Login
        $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        // Assert session ID has changed (regenerated)
        $newSessionId = session()->getId();
        $this->assertNotEquals($oldSessionId, $newSessionId);
    }

    /**
     * Test user registration with valid data
     *
     * @return void
     */
    public function testUserCanRegisterWithValidData()
    {
        $response = $this->post('/register', [
            'fullname' => 'John Doe',
            'username' => 'johndoe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        // Assert redirect to home
        $response->assertRedirect('/home');
        $response->assertSessionHas('success', 'Conta criada com sucesso!');

        // Assert user was created
        $this->assertDatabaseHas('users', [
            'fullname' => 'John Doe',
            'username' => 'johndoe',
            'email' => 'john@example.com',
            'user_type' => 'team',
        ]);

        // Assert user is authenticated after registration
        $this->assertAuthenticated();
    }

    /**
     * Test that registration fails with duplicate email
     *
     * @return void
     */
    public function testRegistrationFailsWithDuplicateEmail()
    {
        // Create existing user
        $this->createTestUser([
            'email' => 'test@example.com',
        ]);

        // Attempt to register with same email
        $response = $this->post('/register', [
            'fullname' => 'John Doe',
            'username' => 'johndoe',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        // Assert validation error
        $response->assertSessionHasErrors(['email']);

        // Assert user is not authenticated
        $this->assertGuest();
    }

    /**
     * Test that registration fails with duplicate username
     *
     * @return void
     */
    public function testRegistrationFailsWithDuplicateUsername()
    {
        // Create existing user
        $this->createTestUser([
            'username' => 'johndoe',
        ]);

        // Attempt to register with same username
        $response = $this->post('/register', [
            'fullname' => 'John Doe',
            'username' => 'johndoe',
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        // Assert validation error
        $response->assertSessionHasErrors(['username']);

        // Assert user is not authenticated
        $this->assertGuest();
    }

    /**
     * Test that registration fails with mismatched passwords
     *
     * @return void
     */
    public function testRegistrationFailsWithMismatchedPasswords()
    {
        $response = $this->post('/register', [
            'fullname' => 'John Doe',
            'username' => 'johndoe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'different123',
        ]);

        // Assert validation error
        $response->assertSessionHasErrors(['password']);

        // Assert user is not authenticated
        $this->assertGuest();
    }

    /**
     * Test that registration fails with short password
     *
     * @return void
     */
    public function testRegistrationFailsWithShortPassword()
    {
        $response = $this->post('/register', [
            'fullname' => 'John Doe',
            'username' => 'johndoe',
            'email' => 'john@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        // Assert validation error
        $response->assertSessionHasErrors(['password']);

        // Assert user is not authenticated
        $this->assertGuest();
    }

    /**
     * Test that password reset page is accessible
     *
     * @return void
     */
    public function testPasswordResetPageIsAccessible()
    {


        $response = $this->get('/password/reset');

        $response->assertStatus(200);
        $response->assertViewIs('auth.passwords.email');
    }

    /**
     * Helper method to create a test user
     */
    protected function createTestUser(array $attributes = []): User
    {
        $defaults = [
            'fullname' => 'Test User',
            'username' => 'testuser' . rand(1000, 9999),
            'email' => 'test' . rand(1000, 9999) . '@example.com',
            'password' => Hash::make('password'),
            'user_type' => 'team',
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $data = array_merge($defaults, $attributes);
        $userId = DB::table('users')->insertGetId($data);

        return User::find($userId);
    }
}
