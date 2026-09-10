<?php

namespace Tests\Smoke;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fast sanity net over every top-level GET route: does it render at all
 * (no fatal error / missing view / undefined variable) and does each role
 * see the access level it's supposed to? This isn't meant to replace the
 * detailed Feature/E2E tests for each screen -- it's meant to catch the
 * class of bug this project has repeatedly had in practice (a route wired
 * to a view that doesn't exist, a role able to see a screen it shouldn't,
 * or vice versa) in one place, fast, so a future change that breaks any
 * single page doesn't slip through unnoticed.
 */
class RouteHealthSmokeTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('guestAccessibleRoutes')]
    public function test_guest_can_load_public_route(string $route)
    {
        $response = $this->get($route);

        $response->assertStatus(200);
    }

    public static function guestAccessibleRoutes(): array
    {
        return [
            'home page' => ['/'],
            'login' => ['/login'],
            'register' => ['/register'],
            'password reset' => ['/password/reset'],
            'health check' => ['/up'],
            'problems list' => ['/exercises'],
            'scoreboard' => ['/scoreboard'],
            'clarifications' => ['/clarifications'],
            'help' => ['/ajuda'],
            'wizard landing' => ['/wizard'],
        ];
    }

    #[DataProvider('authOnlyRoutes')]
    public function test_guest_is_redirected_away_from_auth_only_route(string $route)
    {
        $response = $this->get($route);

        $response->assertRedirect();
    }

    public static function authOnlyRoutes(): array
    {
        return [
            'dashboard' => ['/home'],
            'submissions' => ['/submissions'],
            'admin exercises' => ['/backend/exercises'],
            'admin problem bank' => ['/backend/problem-bank'],
            'admin users' => ['/backend/users'],
            'admin teams' => ['/backend/teams'],
            'admin sites' => ['/backend/sites'],
            'admin configurations' => ['/backend/configurations'],
            'admin contest wizard' => ['/backend/contest-wizard'],
            'judge runs' => ['/judge/runs'],
            'staff tasks' => ['/staff/tasks'],
        ];
    }

    #[DataProvider('teamAccessibleRoutes')]
    public function test_team_can_load_participant_route(string $route)
    {
        $team = $this->createTestUser(['user_type' => 'team']);

        $response = $this->actingAs($team)->get($route);

        $response->assertStatus(200);
    }

    public static function teamAccessibleRoutes(): array
    {
        return [
            'dashboard' => ['/home'],
            'submissions' => ['/submissions'],
            'problems list' => ['/exercises'],
            'scoreboard' => ['/scoreboard'],
        ];
    }

    #[DataProvider('staffOnlyRoutes')]
    public function test_team_is_forbidden_from_staff_only_route(string $route)
    {
        $team = $this->createTestUser(['user_type' => 'team']);

        $response = $this->actingAs($team)->get($route);

        $response->assertStatus(403);
    }

    public static function staffOnlyRoutes(): array
    {
        return [
            'admin exercises' => ['/backend/exercises'],
            'admin problem bank' => ['/backend/problem-bank'],
            'admin users' => ['/backend/users'],
            'admin teams' => ['/backend/teams'],
            'admin sites' => ['/backend/sites'],
            'admin configurations' => ['/backend/configurations'],
            'judge runs' => ['/judge/runs'],
            'staff tasks' => ['/staff/tasks'],
        ];
    }

    public function test_judge_can_load_judge_runs_but_not_staff_tasks_or_admin()
    {
        $judge = $this->createTestUser(['user_type' => 'judge']);

        $this->actingAs($judge)->get('/judge/runs')->assertStatus(200);
        $this->actingAs($judge)->get('/staff/tasks')->assertStatus(403);
        $this->actingAs($judge)->get('/backend/users')->assertStatus(403);
    }

    public function test_staff_can_load_staff_tasks_but_not_judge_runs_or_admin()
    {
        $staff = $this->createTestUser(['user_type' => 'staff']);

        $this->actingAs($staff)->get('/staff/tasks')->assertStatus(200);
        $this->actingAs($staff)->get('/judge/runs')->assertStatus(403);
        $this->actingAs($staff)->get('/backend/users')->assertStatus(403);
    }

    #[DataProvider('adminOnlyRoutes')]
    public function test_admin_can_load_every_admin_route(string $route)
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get($route);

        $response->assertStatus(200);
    }

    public static function adminOnlyRoutes(): array
    {
        return [
            'admin exercises' => ['/backend/exercises'],
            'admin problem bank' => ['/backend/problem-bank'],
            'admin users' => ['/backend/users'],
            'admin teams' => ['/backend/teams'],
            'admin sites' => ['/backend/sites'],
            'admin configurations' => ['/backend/configurations'],
            'admin contest wizard' => ['/backend/contest-wizard'],
            'admin clarifications' => ['/backend/clarifications'],
            'admin submissions' => ['/backend/submissions'],
            'judge runs (admin too)' => ['/judge/runs'],
            'staff tasks (admin too)' => ['/staff/tasks'],
        ];
    }

    public function test_health_check_returns_ok_body()
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
        $response->assertSeeText('OK');
    }
}
