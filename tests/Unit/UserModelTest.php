<?php

namespace Tests\Unit;

use Tests\TestCase;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that a user can be created.
     */
    public function testUserCanBeCreated(): void
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(User::class, $user);
        $this->assertDatabaseHas('users', [
            'user_id' => $user->user_id,
            'email' => $user->email,
        ]);
    }

    /**
     * Test that user attributes are fillable.
     */
    public function testUserAttributesAreFillable(): void
    {
        $userData = [
            'fullname' => 'John Doe',
            'username' => 'johndoe',
            'email' => 'john@example.com',
            'password' => bcrypt('password123'),
            'user_type' => 'team',
            'contest_id' => 1,
            'site_id' => 1,
            'description' => 'Test user description',
            'is_enabled' => true,
        ];

        $user = User::create($userData);

        $this->assertEquals('John Doe', $user->fullname);
        $this->assertEquals('johndoe', $user->username);
        $this->assertEquals('john@example.com', $user->email);
        $this->assertEquals('team', $user->user_type);
        $this->assertEquals(1, $user->contest_id);
        $this->assertEquals(1, $user->site_id);
        $this->assertEquals('Test user description', $user->description);
        $this->assertEquals(true, $user->is_enabled);
    }

    /**
     * Test that password is hashed and verifiable.
     */
    public function testPasswordIsHashedAndVerifiable(): void
    {
        $plainPassword = 'password123';

        $user = User::factory()->create([
            'password' => bcrypt($plainPassword),
        ]);

        // Password should not be stored as plain text
        $this->assertNotEquals($plainPassword, $user->password);

        // Password should be hashed and verifiable
        $this->assertTrue(Hash::check($plainPassword, $user->password));
    }

    /**
     * Test that password is hidden from array/JSON representation.
     */
    public function testPasswordIsHidden(): void
    {
        $user = User::factory()->create();

        $userArray = $user->toArray();

        $this->assertArrayNotHasKey('password', $userArray);
        $this->assertArrayNotHasKey('remember_token', $userArray);
    }

    /**
     * Test isAdmin method returns true for admin users.
     */
    public function testIsAdminReturnsTrueForAdminUsers(): void
    {
        $adminUser = User::factory()->create(['user_type' => User::TYPE_ADMIN]);

        $this->assertTrue($adminUser->isAdmin());
    }

    /**
     * Test isAdmin method returns true for system users.
     */
    public function testIsAdminReturnsTrueForSystemUsers(): void
    {
        $systemUser = User::factory()->create([
            'user_type' => User::TYPE_SYSTEM,
        ]);

        $this->assertTrue($systemUser->isAdmin());
    }

    /**
     * Test isAdmin method returns false for non-admin users.
     */
    public function testIsAdminReturnsFalseForNonAdminUsers(): void
    {
        $teamUser = User::factory()->create([
            'user_type' => User::TYPE_TEAM,
        ]);

        $this->assertFalse($teamUser->isAdmin());
    }

    /**
     * Test isJudge method returns true for judge users.
     */
    public function testIsJudgeReturnsTrueForJudgeUsers(): void
    {
        $judgeUser = User::factory()->create([
            'user_type' => User::TYPE_JUDGE,
        ]);

        $this->assertTrue($judgeUser->isJudge());
    }

    /**
     * Test isJudge method returns false for non-judge users.
     */
    public function testIsJudgeReturnsFalseForNonJudgeUsers(): void
    {
        $teamUser = User::factory()->create([
            'user_type' => User::TYPE_TEAM,
        ]);

        $this->assertFalse($teamUser->isJudge());
    }

    /**
     * Test isParticipant method returns true for team users.
     */
    public function testIsParticipantReturnsTrueForTeamUsers(): void
    {
        $teamUser = User::factory()->create([
            'user_type' => User::TYPE_TEAM,
        ]);

        $this->assertTrue($teamUser->isParticipant());
    }

    /**
     * Test isParticipant method returns false for non-team users.
     */
    public function testIsParticipantReturnsFalseForNonTeamUsers(): void
    {
        $adminUser = User::factory()->create(['user_type' => User::TYPE_ADMIN]);

        $this->assertFalse($adminUser->isParticipant());
    }

    /**
     * Test isStaff method returns true for staff users.
     */
    public function testIsStaffReturnsTrueForStaffUsers(): void
    {
        $staffUser = User::factory()->create([
            'user_type' => User::TYPE_STAFF,
        ]);

        $this->assertTrue($staffUser->isStaff());
    }

    /**
     * Test isSpectator method returns true for score users.
     */
    public function testIsSpectatorReturnsTrueForScoreUsers(): void
    {
        $scoreUser = User::factory()->create([
            'user_type' => User::TYPE_SCORE,
        ]);

        $this->assertTrue($scoreUser->isSpectator());
    }

    /**
     * Test hasRole method with various roles.
     */
    public function testHasRoleMethodWithVariousRoles(): void
    {
        $adminUser = User::factory()->create(['user_type' => User::TYPE_ADMIN]);

        $this->assertTrue($adminUser->hasRole('admin'));
        $this->assertFalse($adminUser->hasRole('participant'));

        $teamUser = User::factory()->create([
            'user_type' => User::TYPE_TEAM,
        ]);

        $this->assertTrue($teamUser->hasRole('participant'));
        $this->assertFalse($teamUser->hasRole('admin'));
    }

    /**
     * Test hasRole method with a role not in the role map.
     */
    public function test_has_role_with_unknown_role(): void
    {
        $customUser = User::factory()->create(['user_type' => 'custom_role']);
        $this->assertTrue($customUser->hasRole('custom_role'));
        $this->assertFalse($customUser->hasRole('another_custom_role'));
    }


    /**
     * Test canAccessBackend method.
     */
    public function testCanAccessBackend(): void
    {
        $adminUser = User::factory()->create(['user_type' => User::TYPE_ADMIN]);
        $this->assertTrue($adminUser->canAccessBackend());

        $judgeUser = User::factory()->create([
            'user_type' => User::TYPE_JUDGE,
        ]);
        $this->assertTrue($judgeUser->canAccessBackend());

        $staffUser = User::factory()->create([
            'user_type' => User::TYPE_STAFF,
        ]);
        $this->assertTrue($staffUser->canAccessBackend());

        $teamUser = User::factory()->create([
            'user_type' => User::TYPE_TEAM,
        ]);
        $this->assertFalse($teamUser->canAccessBackend());
    }

    /**
     * Test canSubmit method.
     */
    public function testCanSubmit(): void
    {
        $adminUser = User::factory()->create(['user_type' => User::TYPE_ADMIN]);
        $this->assertTrue($adminUser->canSubmit());

        $teamUser = User::factory()->create([
            'user_type' => User::TYPE_TEAM,
        ]);
        $this->assertTrue($teamUser->canSubmit());

        $judgeUser = User::factory()->create([
            'user_type' => User::TYPE_JUDGE,
        ]);
        $this->assertFalse($judgeUser->canSubmit());
    }

    /**
     * Test canJudge method.
     */
    public function testCanJudge(): void
    {
        $adminUser = User::factory()->create(['user_type' => User::TYPE_ADMIN]);
        $this->assertTrue($adminUser->canJudge());

        $judgeUser = User::factory()->create([
            'user_type' => User::TYPE_JUDGE,
        ]);
        $this->assertTrue($judgeUser->canJudge());

        $teamUser = User::factory()->create([
            'user_type' => User::TYPE_TEAM,
        ]);
        $this->assertFalse($teamUser->canJudge());
    }

    /**
     * Test canViewScoreboard method - all users can view scoreboard.
     */
    public function testCanViewScoreboard(): void
    {
        $adminUser = User::factory()->create(['user_type' => User::TYPE_ADMIN]);
        $this->assertTrue($adminUser->canViewScoreboard());

        $teamUser = User::factory()->create([
            'user_type' => User::TYPE_TEAM,
        ]);
        $this->assertTrue($teamUser->canViewScoreboard());

        $spectatorUser = User::factory()->create([
            'user_type' => User::TYPE_SCORE,
        ]);
        $this->assertTrue($spectatorUser->canViewScoreboard());
    }

    /**
     * Test default user_type is 'team'.
     */
    public function testDefaultUserTypeIsTeam(): void
    {
        $user = new User([
            'fullname' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->assertEquals(User::TYPE_TEAM, $user->user_type);
    }

    /**
     * Test user primary key is user_id.
     */
    public function testUserPrimaryKeyIsUserId(): void
    {
        $user = new User();

        $this->assertEquals('user_id', $user->getKeyName());
    }

    /**
     * Test user type constants are defined correctly.
     */
    public function testUserTypeConstantsAreDefinedCorrectly(): void
    {
        $this->assertEquals('admin', User::TYPE_ADMIN);
        $this->assertEquals('judge', User::TYPE_JUDGE);
        $this->assertEquals('team', User::TYPE_TEAM);
        $this->assertEquals('staff', User::TYPE_STAFF);
        $this->assertEquals('score', User::TYPE_SCORE);
        $this->assertEquals('system', User::TYPE_SYSTEM);
    }

    /**
     * Test that guarded attributes cannot be mass assigned.
     */
    public function testGuardedAttributesCannotBeMassAssigned(): void
    {
        $userData = [
            'fullname' => 'John Doe',
            'username' => 'johndoe',
            'email' => 'john@example.com',
            'password' => bcrypt('password123'),
            'user_id' => 999, // Should be guarded
            'created_at' => '2000-01-01 00:00:00', // Should be guarded
            'updated_at' => '2000-01-01 00:00:00', // Should be guarded
        ];

        $user = User::create($userData);

        // user_id should not be 999 (it should be auto-incremented)
        $this->assertNotEquals(999, $user->user_id);

        // created_at should not be the provided date (it should be now)
        $this->assertNotEquals('2000-01-01 00:00:00', $user->created_at->format('Y-m-d H:i:s'));
    }
}
