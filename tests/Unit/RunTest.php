<?php

namespace Tests\Unit;

use App\Models\Run;
use App\Models\Contest;
use App\Models\Site;
use Helium\User;
use App\Models\Problem;
use App\Models\Language;
use App\Models\Answer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_belongs_to_a_contest()
    {
        $contest = Contest::factory()->create();
        $run = Run::factory()->create(['contest_id' => $contest->id]);
        $this->assertInstanceOf(Contest::class, $run->contest);
    }

    public function test_run_belongs_to_a_site()
    {
        $site = Site::factory()->create();
        $run = Run::factory()->create(['site_id' => $site->id]);
        $this->assertInstanceOf(Site::class, $run->site);
    }

    public function test_run_belongs_to_a_user()
    {
        $user = User::factory()->create();
        $run = Run::factory()->create(['user_id' => $user->user_id]);
        $this->assertInstanceOf(User::class, $run->user);
    }

    public function test_run_belongs_to_a_problem()
    {
        $problem = Problem::factory()->create();
        $run = Run::factory()->create(['problem_id' => $problem->id]);
        $this->assertInstanceOf(Problem::class, $run->problem);
    }

    public function test_run_belongs_to_a_language()
    {
        $language = Language::factory()->create();
        $run = Run::factory()->create(['language_id' => $language->id]);
        $this->assertInstanceOf(Language::class, $run->language);
    }

    public function test_run_belongs_to_an_answer()
    {
        $answer = Answer::factory()->create();
        $run = Run::factory()->create(['answer_id' => $answer->id]);
        $this->assertInstanceOf(Answer::class, $run->answer);
    }

    public function test_is_pending_returns_correct_value()
    {
        $pendingRun = Run::factory()->create(['status' => 'pending']);
        $judgedRun = Run::factory()->create(['status' => 'judged']);
        $this->assertTrue($pendingRun->isPending());
        $this->assertFalse($judgedRun->isPending());
    }

    public function test_is_judged_returns_correct_value()
    {
        $judgedRun = Run::factory()->create(['status' => 'judged', 'answer_id' => 1]);
        $pendingRun = Run::factory()->create(['status' => 'pending']);
        $judgedWithoutAnswer = Run::factory()->create(['status' => 'judged', 'answer_id' => null]);

        $this->assertTrue($judgedRun->isJudged());
        $this->assertFalse($pendingRun->isJudged());
        $this->assertFalse($judgedWithoutAnswer->isJudged());
    }

    public function test_is_accepted_returns_correct_value()
    {
        $acceptedAnswer = Answer::factory()->create(['is_accepted' => true]);
        $wrongAnswer = Answer::factory()->create(['is_accepted' => false]);
        
        $acceptedRun = Run::factory()->create(['status' => 'judged', 'answer_id' => $acceptedAnswer->id]);
        $wrongAnswerRun = Run::factory()->create(['status' => 'judged', 'answer_id' => $wrongAnswer->id]);
        $pendingRun = Run::factory()->create(['status' => 'pending']);

        $this->assertTrue($acceptedRun->isAccepted());
        $this->assertFalse($wrongAnswerRun->isAccepted());
        $this->assertFalse($pendingRun->isAccepted());
    }

    public function test_get_contest_time_formatted()
    {
        $run = Run::factory()->make(['contest_time' => 3661]); // 1 hour, 1 minute, 1 second
        $this->assertEquals('01:01:01', $run->getContestTimeFormatted());

        $run2 = Run::factory()->make(['contest_time' => 59]);
        $this->assertEquals('00:00:59', $run2->getContestTimeFormatted());
    }

    public function test_get_next_run_number()
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);

        $this->assertEquals(1, Run::getNextRunNumber($contest->id, $site->id));

        Run::factory()->create(['contest_id' => $contest->id, 'site_id' => $site->id, 'run_number' => 1]);
        $this->assertEquals(2, Run::getNextRunNumber($contest->id, $site->id));

        Run::factory()->create(['contest_id' => $contest->id, 'site_id' => $site->id, 'run_number' => 2]);
        $this->assertEquals(3, Run::getNextRunNumber($contest->id, $site->id));
    }

    public function test_run_belongs_to_a_judge()
    {
        $judge = User::factory()->create(['user_type' => 'judge']);
        $run = Run::factory()->create(['judge_id' => $judge->user_id]);
        $this->assertInstanceOf(User::class, $run->judge);
    }

    public function test_run_belongs_to_a_judge_site()
    {
        $site = Site::factory()->create();
        $run = Run::factory()->create(['judge_site_id' => $site->id]);
        $this->assertInstanceOf(Site::class, $run->judgeSite);
    }

    public function test_get_source_path()
    {
        $run = Run::factory()->make(['source_file' => 'submissions/test.cpp']);
        $expectedPath = storage_path("app/submissions/test.cpp");
        $this->assertEquals($expectedPath, $run->getSourcePath());
    }
}
