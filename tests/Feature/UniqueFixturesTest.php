<?php

namespace Tests\Feature;

use App\Models\ProblemBank;
use Helium\User;
use Tests\TestCase;

/**
 * Fixtures that feed a UNIQUE column must not draw from a small random
 * range.
 *
 * This is the third time the same mistake produced an intermittent
 * failure: AnswerFactory drew two letters for `answers.short_name`,
 * TestCase::createTestUser() drew rand(1000, 9999) for `users.username`,
 * and three bank helpers drew a similar range for `problem_bank.code`.
 * Each one fails rarely enough to look like infrastructure flakiness and
 * often enough to erode trust in a red build.
 *
 * Both tests below fail on the old implementations by the birthday
 * problem, not by luck: three hundred draws from nine thousand slots
 * collide with probability indistinguishable from one.
 */
class UniqueFixturesTest extends TestCase
{
    public function test_three_hundred_users_can_be_created_without_a_username_collision(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $this->createTestUser();
        }

        $this->assertSame(300, User::count());
        $this->assertSame(300, User::distinct()->count('username'));
    }

    public function test_the_unique_suffix_never_repeats_within_a_process(): void
    {
        $seen = [];

        for ($i = 0; $i < 500; $i++) {
            $seen[] = $this->uniqueSuffix();
        }

        $this->assertCount(500, array_unique($seen));
    }

    public function test_three_hundred_bank_entries_can_be_created_without_a_code_collision(): void
    {
        for ($i = 0; $i < 300; $i++) {
            ProblemBank::create([
                'code' => 'CODE'.$this->uniqueSuffix(),
                'name' => 'Problema '.$this->uniqueSuffix(),
                'description' => 'd',
                'input_description' => 'i',
                'output_description' => 'o',
                'tags' => [],
                'is_active' => true,
                'version' => 1,
            ]);
        }

        $this->assertSame(300, ProblemBank::distinct()->count('code'));
    }
}
