<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sidebar (resources/views/layouts/app.blade.php) used to show the
 * Administração section to every authenticated user regardless of role --
 * routes were already protected server-side, but a team/judge/staff user
 * would see admin links that immediately 403'd if clicked. Confirms the
 * role-gated sections only render for the roles they belong to.
 */
class RoleBasedNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_user_does_not_see_admin_judge_or_staff_sections()
    {
        $team = $this->createTestUser(['user_type' => 'team']);

        $response = $this->actingAs($team)->get('/home');

        $response->assertStatus(200);
        $response->assertDontSeeText('Administração');
        $response->assertDontSeeText('Julgamento');
        $response->assertDontSeeText('Tarefas');
    }

    public function test_admin_user_sees_all_role_sections()
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/home');

        $response->assertStatus(200);
        $response->assertSeeText('Administração');
        $response->assertSeeText('Julgamento');
        $response->assertSeeText('Staff');
    }

    public function test_judge_user_sees_only_the_judge_section()
    {
        $judge = $this->createTestUser(['user_type' => 'judge']);

        $response = $this->actingAs($judge)->get('/home');

        $response->assertStatus(200);
        $response->assertSeeText('Julgamento');
        $response->assertDontSeeText('Administração');
        $response->assertDontSeeText('Staff');
    }

    public function test_staff_user_sees_only_the_staff_section()
    {
        $staff = $this->createTestUser(['user_type' => 'staff']);

        $response = $this->actingAs($staff)->get('/home');

        $response->assertStatus(200);
        $response->assertSeeText('Staff');
        $response->assertDontSeeText('Administração');
        $response->assertDontSeeText('Julgamento');
    }
}
