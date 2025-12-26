<?php

namespace Tests\Feature\Backend;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test admin can view the user list page.
     */
    public function test_admin_can_view_users_index()
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get(route('backend.users'));

        $response->assertStatus(200);
        $response->assertViewIs('backend.users');
        $response->assertViewHas('users');
    }

    /**
     * Test admin can create a new user.
     */
    public function test_admin_can_create_user()
    {
        $admin = $this->createAdminUser();
        $userData = [
            'fullname' => 'New User',
            'username' => 'newuser',
            'email' => 'new@example.com',
            'password' => 'password123',
            'user_type' => 'team',
        ];

        $response = $this->actingAs($admin)->post(route('backend.users'), $userData);

        $response->assertRedirect(route('backend.users'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('users', ['username' => 'newuser']);
    }

    /**
     * Test admin can delete a user.
     */
    public function test_admin_can_delete_user()
    {
        $admin = $this->createAdminUser();
        $userToDelete = $this->createTestUser();

        $this->assertDatabaseHas('users', ['user_id' => $userToDelete->user_id]);

        $response = $this->actingAs($admin)->delete(route('backend.users.destroy', $userToDelete->user_id));

        $response->assertRedirect(route('backend.users'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('users', ['user_id' => $userToDelete->user_id]);
    }

    /**
     * Test non-admin cannot access user management.
     */
    public function test_non_admin_cannot_access_user_management()
    {
        $user = $this->createTestUser();

        $response = $this->actingAs($user)->get(route('backend.users'));
        $response->assertStatus(403);

        $response = $this->actingAs($user)->post(route('backend.users'), []);
        $response->assertStatus(403);

        $response = $this->actingAs($user)->delete(route('backend.users.destroy', 1));
        $response->assertStatus(403);
    }
}
