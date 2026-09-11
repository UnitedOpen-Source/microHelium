<?php

namespace Tests\Unit;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Leaderboard;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Score;
use App\Models\Site;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression net for Contest::getContestTime()'s sign-inversion bug: it
 * previously returned a NEGATIVE elapsed time for the entire duration a
 * contest was running (now()->diffInSeconds($start) has receiver/argument
 * reversed under Carbon 3's signed-by-default diff semantics). Score::
 * updateScore() derives solved_time from that value, and
 * Leaderboard::getScoreboard() ranks by total_time ascending -- so with the
 * bug, a LATER solve produced a MORE NEGATIVE total_time and ranked BETTER,
 * inverting the scoreboard for every contest. This test exercises the real
 * pipeline end to end (not a fixed/literal total_time) and asserts the
 * earlier solver ranks first, the way an actual contest depends on.
 */
class ScoreLeaderboardRankingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_team_that_solves_earlier_ranks_ahead_of_a_later_solver()
    {
        $contest = Contest::factory()->create([
            'start_time' => now()->subMinutes(30),
            'is_active' => true,
            'penalty' => 20,
        ]);
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id]);
        $accepted = Answer::factory()->create(['contest_id' => $contest->id, 'is_accepted' => true]);

        $earlySolver = User::factory()->create(['site_id' => $site->id]);
        $lateSolver = User::factory()->create(['site_id' => $site->id]);

        // Real elapsed-time computation via the same Contest::getContestTime()
        // pipeline SubmitController uses -- not a literal/fixed value.
        $earlyRun = Run::factory()->create([
            'contest_id' => $contest->id,
            'user_id' => $earlySolver->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'status' => 'judged',
            'answer_id' => $accepted->id,
            'contest_time' => $contest->getContestTime(),
        ]);

        $this->travel(10)->minutes();

        $lateRun = Run::factory()->create([
            'contest_id' => $contest->id,
            'user_id' => $lateSolver->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'status' => 'judged',
            'answer_id' => $accepted->id,
            'contest_time' => $contest->getContestTime(),
        ]);

        Score::updateScore($earlyRun);
        Score::updateScore($lateRun);

        $earlyScore = Score::where('user_id', $earlySolver->user_id)->where('problem_id', $problem->id)->first();
        $lateScore = Score::where('user_id', $lateSolver->user_id)->where('problem_id', $problem->id)->first();

        $this->assertGreaterThan(0, $earlyScore->solved_time, 'solved_time must be positive, not the negative pre-fix value');
        $this->assertGreaterThan($earlyScore->solved_time, $lateScore->solved_time, 'the later solver must have a larger (worse) solved_time');

        $earlyEntry = Leaderboard::where('contest_id', $contest->id)->where('user_id', $earlySolver->user_id)->first();
        $lateEntry = Leaderboard::where('contest_id', $contest->id)->where('user_id', $lateSolver->user_id)->first();

        $this->assertLessThan($lateEntry->rank, $earlyEntry->rank, 'the earlier solver must rank ahead of (a lower rank number than) the later solver');

        $scoreboard = Leaderboard::getScoreboard($contest->id);
        $this->assertSame($earlySolver->user_id, $scoreboard[0]['user']->user_id, 'the earlier solver must appear first on the scoreboard');
    }
}
