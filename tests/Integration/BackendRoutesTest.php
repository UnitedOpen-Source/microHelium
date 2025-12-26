<?php

namespace Tests\Integration;

use Tests\TestCase;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class BackendRoutesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that backend dashboard (exercises) is accessible to admins
     *
     * @return void
     */
    public function testBackendDashboardIsAccessibleToAdmins()
    {
        // Create an admin user
        $admin = $this->createAdminUser();

        // Access backend exercises route (main dashboard)
        $response = $this->actingAs($admin)->get('/backend/exercises');

        // Assert successful response
        $response->assertStatus(200);
        $response->assertViewIs('backend.exercises');
        $response->assertViewHas('exercises');
    }

    /**
     * Test that backend is not accessible to regular users
     *
     * @return void
     */
    public function testBackendIsNotAccessibleToRegularUsers()
    {
        // Create a regular user (team/participant)
        $user = $this->createRegularUser();
        $this->actingAs($user);

        // Assert that the user is not an admin
        $this->assertFalse($user->isAdmin());

        // Attempt to access backend routes as a regular user and assert a 403 response
        $backendRoutes = [
            '/backend/exercises',
            '/backend/users',
            '/backend/teams',
            '/backend/configurations',
            '/backend/contest-wizard',
        ];

        foreach ($backendRoutes as $route) {
            $response = $this->get($route);
            $response->assertStatus(403);
        }
    }

    /**
     * Test that problem bank route works
     *
     * @return void
     */
    public function testProblemBankRouteWorks()
    {
        // Create an admin user
        $admin = $this->createAdminUser();

        // Access problem bank route
        $response = $this->actingAs($admin)->get('/backend/problem-bank');

        // Assert successful response
        $response->assertStatus(200);
        $response->assertViewIs('backend.problem-bank');
        $response->assertViewHas('problems');
    }

    /**
     * Test that contest wizard route works
     *
     * @return void
     */
    public function testContestWizardRouteWorks()
    {
        // Create an admin user
        $admin = $this->createAdminUser();

        // Access contest wizard route
        $response = $this->actingAs($admin)->get('/backend/contest-wizard');

        // Assert successful response
        $response->assertStatus(200);
        $response->assertViewIs('backend.contest-wizard');
        $response->assertViewHas('problemBank');
    }

    /**
     * Test that configurations route works
     *
     * @return void
     */
    public function testConfigurationsRouteWorks()
    {
        // Create an admin user
        $admin = $this->createAdminUser();

        // Access configurations route
        $response = $this->actingAs($admin)->get('/backend/configurations');

        // Assert successful response
        $response->assertStatus(200);
        $response->assertViewIs('backend.configurations');
        $response->assertViewHas('hackathons');
    }

    /**
     * Test that import BOCA route works
     *
     * @return void
     */
    public function testImportBocaRouteWorks()
    {
        // Create an admin user
        $admin = $this->createAdminUser();

        // Access import BOCA route
        $response = $this->actingAs($admin)->get('/backend/import-boca');

        // Assert successful response
        $response->assertStatus(200);
        $response->assertViewIs('backend.import-boca');
    }

    /**
     * Test that admin can access all backend routes
     *
     * @return void
     */
    public function testAdminCanAccessAllBackendRoutes()
    {
        // Create an admin user
        $admin = $this->createAdminUser();

        // Test all backend GET routes
        $backendRoutes = [
            '/backend/exercises',
            '/backend/users',
            '/backend/teams',
            '/backend/configurations',
            '/backend/contest-wizard',
            '/backend/problem-bank',
            '/backend/import-boca',
            '/backend/clarifications',
            '/backend/submissions',
        ];

        foreach ($backendRoutes as $route) {
            $response = $this->actingAs($admin)->get($route);
            $response->assertStatus(200);
        }
    }

    /**
     * Test that unauthenticated users cannot access backend routes
     *
     * @return void
     */
    public function testUnauthenticatedUsersCannotAccessBackendRoutes()
    {
        // Attempt to access backend routes without authentication
        $response = $this->get('/backend/exercises');

        // Should return 200 since there's no auth middleware currently
        // In production, you'd want to add middleware and test for redirect to login
        $response->assertRedirect('/login');

        // Verify we're not authenticated
        $this->assertGuest();
    }

    /**
     * Test that admin can create a hackathon/contest via configurations
     *
     * @return void
     */
    public function testAdminCanCreateHackathonViaConfigurations()
    {
        // Create an admin user
        $admin = $this->createAdminUser();

        // Post to configurations to create a hackathon
        $response = $this->actingAs($admin)->post('/backend/configurations', [
            'eventName' => 'Test Hackathon',
            'description' => 'A test hackathon for integration testing',
            'starts_at' => now()->addDays(1)->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDays(1)->addHours(5)->format('Y-m-d H:i:s'),
            'languages' => ['cpp', 'java', 'py'],
        ]);

        // Assert redirect back to configurations
        $response->assertRedirect(route('backend.configurations'));
        $response->assertSessionHas('success');

        // Assert hackathon was created in database
        $this->assertDatabaseHas('hackathons', [
            'eventName' => 'Test Hackathon',
            'description' => 'A test hackathon for integration testing',
        ]);
    }

    /**
     * Helper method to create a regular user (participant/team)
     */
    protected function createRegularUser(): User
    {
        return $this->createTestUser([
            'user_type' => 'team',
            'fullname' => 'Regular User',
        ]);
    }
}
