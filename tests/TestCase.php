<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Counts up for the lifetime of the process, so fixtures that feed a
     * UNIQUE column get a value that cannot collide.
     *
     * rand() was used for this, and it is the wrong tool: `users.username`
     * and `problem_bank.code` are unique, and a draw from a few thousand
     * values collides often enough to fail a run now and then. The same
     * mistake in AnswerFactory produced an intermittent CI failure that
     * took a while to attribute -- see the AnswerFactory fix.
     */
    private static int $uniqueSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Create a test user for authentication tests
     */
    protected function createTestUser(array $attributes = []): \Helium\User
    {
        $suffix = $this->uniqueSuffix();

        $defaults = [
            'fullname' => 'Test User',
            'username' => 'testuser' . $suffix,
            'email' => 'test' . $suffix . '@example.com',
            'password' => bcrypt('password'),
            'user_type' => 'team',
            'is_enabled' => true,
        ];

        return \Helium\User::create(array_merge($defaults, $attributes));
    }

    /**
     * A value no other fixture in this process has used.
     */
    protected function uniqueSuffix(): string
    {
        return (string) ++self::$uniqueSequence;
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
