<?php

namespace Tests\Feature;

use App\Models\Contest;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #189 -- the freeze has to outlive the contest.
 *
 * It did not. `isFrozen()` began with `if (! $this->isRunning()) return
 * false;`, and `isRunning()` requires now() <= end_time, so the freeze
 * expired with the contest: the full final standings -- including the last
 * hour the freeze exists to hide -- went public at the exact second the
 * clock ran out, to teams and anonymous visitors alike, while the teams
 * were still leaving the room.
 *
 * In ICPC the freeze surviving the end IS the ceremony, and #44's
 * Animeitor-style webcast assumes a frozen state that lasts until somebody
 * ends it.
 */
class ScoreboardFreezeLifecycleTest extends TestCase
{
    /**
     * A five-hour contest that froze an hour before the end, now finished.
     */
    private function finishedContest(int $freezeMinutes = 60): Contest
    {
        $contest = Contest::factory()->create([
            'is_active' => true,
            'start_time' => now()->subHours(4),
            'duration' => 300,
            'freeze_time' => $freezeMinutes,
        ]);

        $this->travel(90)->minutes();

        return $contest->fresh();
    }

    public function test_the_freeze_survives_the_end_of_the_contest(): void
    {
        $contest = $this->finishedContest();

        $this->assertFalse($contest->isRunning(), 'the fixture is meant to be a finished contest');
        $this->assertTrue(
            $contest->isFrozen(),
            'the standings unfroze themselves when the clock ran out, before anyone revealed them'
        );
    }

    public function test_an_admin_reveals_the_standings_and_only_then_do_they_unfreeze(): void
    {
        $contest = $this->finishedContest();

        Sanctum::actingAs($this->createAdminUser());

        $this->postJson("/api/contests/{$contest->id}/unfreeze")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Placar final revelado.');

        $contest->refresh();

        $this->assertFalse($contest->isFrozen());
        $this->assertTrue($contest->isUnfrozen());
        $this->assertNotNull($contest->unfrozen_at);
    }

    /**
     * The check that matters most: revealing while anyone is still
     * competing publishes the answers to a live contest.
     */
    public function test_the_standings_cannot_be_revealed_while_the_contest_is_running(): void
    {
        $contest = Contest::factory()->create([
            'is_active' => true,
            'start_time' => now()->subHours(4),
            'duration' => 300,
            'freeze_time' => 60,
        ]);

        $this->assertTrue($contest->isRunning());

        Sanctum::actingAs($this->createAdminUser());

        $this->postJson("/api/contests/{$contest->id}/unfreeze")->assertStatus(422);

        $this->assertTrue($contest->fresh()->isFrozen());
    }

    /**
     * The button lives on a stage. A second press must not read as failure.
     */
    public function test_revealing_twice_is_not_an_error_and_does_not_move_the_moment(): void
    {
        $contest = $this->finishedContest();

        Sanctum::actingAs($this->createAdminUser());

        $this->postJson("/api/contests/{$contest->id}/unfreeze")->assertStatus(200);
        $first = $contest->fresh()->unfrozen_at;

        $this->travel(5)->minutes();

        $this->postJson("/api/contests/{$contest->id}/unfreeze")
            ->assertStatus(200)
            ->assertJsonPath('message', 'O placar ja havia sido revelado.');

        $this->assertEquals($first, $contest->fresh()->unfrozen_at, 'the release moment was rewritten');
    }

    public function test_a_team_cannot_reveal_the_standings(): void
    {
        $contest = $this->finishedContest();

        Sanctum::actingAs($this->createTestUser(['user_type' => 'team', 'contest_id' => $contest->id]));

        $this->postJson("/api/contests/{$contest->id}/unfreeze")->assertStatus(403);

        $this->assertTrue($contest->fresh()->isFrozen());
    }

    /**
     * `freeze_time` of 0 means "no freeze at all" and must keep meaning
     * that. The obvious fix for this issue -- drop the isRunning() guard --
     * turns such a contest frozen the instant it ends and leaves it frozen
     * for ever: the same defect with the opposite sign.
     */
    public function test_a_contest_configured_without_a_freeze_never_freezes(): void
    {
        $contest = $this->finishedContest(freezeMinutes: 0);

        $this->assertFalse($contest->isFrozen(), 'a contest with no freeze window froze itself after it ended');
        $this->assertFalse($contest->isUnfrozen());
    }

    public function test_a_contest_that_has_not_started_is_not_frozen(): void
    {
        $contest = Contest::factory()->create([
            'is_active' => true,
            'start_time' => now()->addHour(),
            'duration' => 300,
            'freeze_time' => 60,
        ]);

        $this->assertFalse($contest->isFrozen());
    }

    /**
     * The whole point, stated as the scoreboard sees it: a team asking for
     * the standings after the contest ends gets the frozen board until the
     * ceremony.
     */
    public function test_the_scoreboard_a_team_reads_after_the_end_is_still_the_frozen_one(): void
    {
        $contest = $this->finishedContest();
        $team = $this->createTestUser(['user_type' => 'team', 'contest_id' => $contest->id]);

        Sanctum::actingAs($team);
        $this->getJson("/api/contests/{$contest->id}/scoreboard")
            ->assertStatus(200)
            ->assertJsonPath('contest.is_frozen', true);

        Sanctum::actingAs($this->createAdminUser());
        $this->postJson("/api/contests/{$contest->id}/unfreeze")->assertStatus(200);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($team);
        $this->getJson("/api/contests/{$contest->id}/scoreboard")
            ->assertStatus(200)
            ->assertJsonPath('contest.is_frozen', false);
    }
}
