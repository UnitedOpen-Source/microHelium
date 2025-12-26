<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Create a test user for authentication tests
     */
    protected function createTestUser(array $attributes = []): \Helium\User
    {
        $defaults = [
            'fullname' => 'Test User',
            'username' => 'testuser' . rand(1000, 9999),
            'email' => 'test' . rand(1000, 9999) . '@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'team',
            'is_enabled' => true,
        ];

        return \Helium\User::create(array_merge($defaults, $attributes));
    }

    /**
     * Create a test admin user
     */
    protected function createAdminUser(): \Helium\User
    {
        return $this->createTestUser([
            'user_type' => 'admin',
        ]);
    }

    /**
     * Create a test contest
     */
    protected function createTestContest(array $attributes = []): object
    {
        return (object) \Illuminate\Support\Facades\DB::table('contests')->insertGetId(array_merge([
            'name' => 'Test Contest',
            'description' => 'Test description',
            'start_time' => now()->addHour(),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }
}
