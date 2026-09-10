<?php

namespace Tests\Feature\Backend;

use App\Models\Contest;
use App\Models\Site;
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
     * The site_id/contest_id columns and the User->site() relation existed
     * but store() never populated them -- there was no way to assign any
     * created user to a site at all (issue #18 prerequisite fix).
     */
    public function test_admin_can_assign_a_site_when_creating_a_user()
    {
        $admin = $this->createAdminUser();
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);

        $response = $this->actingAs($admin)->post(route('backend.users'), [
            'fullname' => 'Site Coordinator',
            'username' => 'sitecoord',
            'email' => 'sitecoord@example.com',
            'password' => 'password123',
            'user_type' => 'site',
            'site_id' => $site->id,
        ]);

        $response->assertRedirect(route('backend.users'));
        $this->assertDatabaseHas('users', [
            'username' => 'sitecoord',
            'user_type' => 'site',
            'site_id' => $site->id,
            'contest_id' => $contest->id,
        ]);
    }

    public function test_site_user_type_requires_a_site_id()
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post(route('backend.users'), [
            'fullname' => 'Site Coordinator',
            'username' => 'sitecoord2',
            'email' => 'sitecoord2@example.com',
            'password' => 'password123',
            'user_type' => 'site',
        ]);

        $response->assertSessionHasErrors('site_id');
        $this->assertDatabaseMissing('users', ['username' => 'sitecoord2']);
    }

    public function test_invalid_user_type_is_rejected()
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post(route('backend.users'), [
            'fullname' => 'Bad Type',
            'username' => 'badtype',
            'email' => 'badtype@example.com',
            'password' => 'password123',
            'user_type' => 'superuser',
        ]);

        $response->assertSessionHasErrors('user_type');
        $this->assertDatabaseMissing('users', ['username' => 'badtype']);
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
