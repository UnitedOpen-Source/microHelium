<?php

namespace Tests\Smoke;

use App\Models\Contest;
use App\Models\Run;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fast sanity net over the API surface (routes/api.php), mirroring
 * RouteHealthSmokeTest's approach for the web routes -- this suite had no
 * coverage at all for the API before issue #52/#64/#65's fixes, so a wiring
 * mistake there (a route left unauthenticated, a role middleware typo, a
 * public endpoint that starts requiring auth) could ship unnoticed. Not a
 * replacement for the detailed Integration tests per controller -- this is
 * the one place that fails fast if the auth/role wiring itself regresses.
 */
class ApiRouteHealthSmokeTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('guestAccessibleApiRoutes')]
    public function test_guest_can_load_public_api_route(string $route)
    {
        $response = $this->getJson($route);

        $response->assertStatus(200);
    }

    public static function guestAccessibleApiRoutes(): array
    {
        return [
            'health check' => ['/api/health'],
            'current contest' => ['/api/contest/current'],
            'openapi spec' => ['/api/openapi.yaml'],
        ];
    }

    #[DataProvider('authOnlyApiRoutes')]
    public function test_guest_gets_unauthorized_from_auth_only_api_route(string $method, string $route)
    {
        $response = $this->json($method, $route);

        $response->assertStatus(401);
    }

    public static function authOnlyApiRoutes(): array
    {
        return [
            'authenticated user' => ['GET', '/api/user'],
            'contests index' => ['GET', '/api/contests'],
            'problems index' => ['GET', '/api/problems'],
            'runs index' => ['GET', '/api/runs'],
            'clarifications index' => ['GET', '/api/clarifications'],
        ];
    }

    /**
     * Issue #64/#66: judge()/rejudge()/answer() previously enforced no role
     * check beyond auth:sanctum. This is the fast regression net for that
     * class of mistake -- if the role:judge,admin middleware is ever
     * dropped from routes/api.php again, this fails immediately.
     */
    #[DataProvider('judgeOnlyApiActions')]
    public function test_team_is_forbidden_from_judge_only_api_action(string $method, \Closure $route)
    {
        $team = User::factory()->create(['user_type' => 'team']);
        \Laravel\Sanctum\Sanctum::actingAs($team);

        $response = $this->json($method, $route());

        $response->assertStatus(403);
    }

    public static function judgeOnlyApiActions(): array
    {
        return [
            'judge a run' => ['PUT', fn () => '/api/runs/' . Run::factory()->create()->id . '/judge'],
            'rejudge a run' => ['POST', fn () => '/api/runs/' . Run::factory()->create()->id . '/rejudge'],
            'answer a clarification' => ['PUT', fn () => '/api/clarifications/' . \App\Models\Clarification::factory()->create()->id . '/answer'],
        ];
    }

    public function test_admin_can_load_authenticated_api_routes()
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        $this->getJson('/api/user')->assertStatus(200);
        $this->getJson('/api/contests')->assertStatus(200);
        $this->getJson('/api/runs')->assertStatus(200);
        $this->getJson('/api/clarifications')->assertStatus(200);
    }

    public function test_contest_scoreboard_endpoints_are_reachable_for_a_real_contest()
    {
        $contest = Contest::factory()->create();
        $admin = User::factory()->create(['user_type' => 'admin']);
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        $this->getJson("/api/contests/{$contest->id}/scoreboard")->assertStatus(200);
        $this->getJson("/api/contests/{$contest->id}/statistics")->assertStatus(200);
        $this->getJson("/api/contests/{$contest->id}/status")->assertStatus(200);
    }
}
