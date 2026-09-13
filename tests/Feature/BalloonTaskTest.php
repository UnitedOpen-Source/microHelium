<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Score;
use App\Models\Site;
use App\Models\Task;
use App\Services\Practice\PracticeContest;
use Helium\User;
use Tests\TestCase;

/**
 * Issue #87 -- a team's first accepted run becomes a balloon delivery task
 * for the staff at their site.
 *
 * Before this, nothing in app/ ever created a Task, so the staff screen was
 * structurally empty in any real contest while the tests passed on
 * factory-made rows.
 */
class BalloonTaskTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    private Problem $problem;

    private Language $language;

    private Answer $accepted;

    private Answer $wrong;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create([
            'start_time' => now()->subMinutes(30),
            'is_active' => true,
            'penalty' => 20,
        ]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problem = Problem::factory()->create([
            'contest_id' => $this->contest->id,
            'short_name' => 'C',
            'name' => 'Caminhos',
            'color_name' => 'Vermelho',
            'color_hex' => '#ff0000',
        ]);
        $this->language = Language::factory()->create(['contest_id' => $this->contest->id]);
        $this->accepted = Answer::factory()->create(['contest_id' => $this->contest->id, 'is_accepted' => true]);
        $this->wrong = Answer::factory()->create(['contest_id' => $this->contest->id, 'is_accepted' => false]);
    }

    private function team(?Site $site = null, string $type = 'team'): User
    {
        return User::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => ($site ?? $this->site)->id,
            'user_type' => $type,
        ]);
    }

    private function judge(User $user, ?Problem $problem = null, bool $accepted = true, ?Site $site = null, ?Contest $contest = null): Run
    {
        $contest ??= $this->contest;
        $site ??= $this->site;

        $run = Run::factory()->create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'user_id' => $user->user_id,
            'problem_id' => ($problem ?? $this->problem)->id,
            'language_id' => $this->language->id,
            'status' => 'judged',
            'answer_id' => $accepted ? $this->accepted->id : $this->wrong->id,
            'contest_time' => 600,
        ]);

        Score::updateScore($run);

        return $run;
    }

    public function test_a_first_accepted_run_raises_a_balloon_task_with_the_problems_colour(): void
    {
        $team = $this->team();

        $this->judge($team);

        $task = Task::firstOrFail();
        $this->assertSame($this->contest->id, $task->contest_id);
        $this->assertSame($this->site->id, $task->site_id);
        $this->assertSame($team->user_id, $task->user_id);
        $this->assertSame($this->problem->id, $task->problem_id);
        $this->assertSame('pending', $task->status);
        $this->assertTrue($task->is_system);
        $this->assertSame('Vermelho', $task->color_name);
        $this->assertSame('#ff0000', $task->color_hex);
        $this->assertStringContainsString('C', $task->description);
        $this->assertStringContainsString('Caminhos', $task->description);
        $this->assertSame(1, $task->task_number);
    }

    public function test_a_wrong_answer_raises_nothing(): void
    {
        $this->judge($this->team(), accepted: false);

        $this->assertSame(0, Task::count());
    }

    public function test_a_rejudge_does_not_hand_out_a_second_balloon(): void
    {
        $team = $this->team();

        $this->judge($team);
        $this->judge($team);

        $this->assertSame(1, Task::count());
    }

    public function test_each_solved_problem_gets_its_own_balloon(): void
    {
        $team = $this->team();
        $other = Problem::factory()->create([
            'contest_id' => $this->contest->id,
            'short_name' => 'D',
            'color_name' => 'Azul',
            'color_hex' => '#0000ff',
        ]);

        $this->judge($team);
        $this->judge($team, $other);

        $this->assertSame(2, Task::count());
        $this->assertSame([1, 2], Task::orderBy('task_number')->pluck('task_number')->all());
        $this->assertSame(['Azul', 'Vermelho'], Task::orderBy('color_name')->pluck('color_name')->all());
    }

    public function test_task_numbers_run_per_site_not_globally(): void
    {
        $otherSite = Site::factory()->create(['contest_id' => $this->contest->id]);

        $this->judge($this->team());
        $this->judge($this->team($otherSite), site: $otherSite);

        // The unique key is (contest_id, site_id, task_number): each site
        // counts from 1.
        $this->assertSame([1, 1], Task::orderBy('id')->pluck('task_number')->all());
    }

    public function test_a_non_team_account_raises_no_balloon(): void
    {
        // routes/api.php lets any authenticated account create a Run, so an
        // admin debugging a problem must not send anyone walking.
        $this->judge($this->team(type: 'admin'));

        $this->assertSame(0, Task::count());
    }

    public function test_practice_submissions_raise_no_balloons(): void
    {
        $practice = app(PracticeContest::class)->contest();
        $practiceSite = $practice->sites()->firstOrFail();
        $practiceProblem = Problem::factory()->create(['contest_id' => $practice->id]);
        $practiceLanguage = $practice->languages()->firstOrFail();
        $practiceAccepted = $practice->answers()->where('short_name', 'AC')->firstOrFail();

        $team = User::factory()->create(['user_type' => 'team', 'site_id' => $practiceSite->id]);

        $run = Run::factory()->create([
            'contest_id' => $practice->id,
            'site_id' => $practiceSite->id,
            'user_id' => $team->user_id,
            'problem_id' => $practiceProblem->id,
            'language_id' => $practiceLanguage->id,
            'status' => 'judged',
            'answer_id' => $practiceAccepted->id,
            'contest_time' => 0,
        ]);

        Score::updateScore($run);

        // Issue #43: practice has no ceremony, no staff and no balloons.
        $this->assertSame(0, Task::count());
    }

    // --- the staff screen ------------------------------------------------

    public function test_staff_see_their_own_sites_queue_only(): void
    {
        $otherSite = Site::factory()->create(['contest_id' => $this->contest->id]);

        $mine = $this->team();
        $theirs = $this->team($otherSite);
        $this->judge($mine);
        $this->judge($theirs, site: $otherSite);

        $staff = User::factory()->create([
            'user_type' => 'staff',
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
        ]);

        $tasks = $this->actingAs($staff)->get('/staff/tasks')->assertOk()->viewData('tasks');

        $this->assertCount(1, $tasks);
        $this->assertSame($this->site->id, $tasks->first()->site_id);
    }

    public function test_an_admin_sees_every_site(): void
    {
        $otherSite = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->judge($this->team());
        $this->judge($this->team($otherSite), site: $otherSite);

        $admin = User::factory()->create([
            'user_type' => 'admin',
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
        ]);

        $this->assertCount(2, $this->actingAs($admin)->get('/staff/tasks')->assertOk()->viewData('tasks'));
    }

    public function test_staff_cannot_complete_another_sites_task_by_guessing_its_id(): void
    {
        $otherSite = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->judge($this->team($otherSite), site: $otherSite);
        $task = Task::firstOrFail();

        $staff = User::factory()->create([
            'user_type' => 'staff',
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
        ]);

        $this->actingAs($staff)->post("/staff/tasks/{$task->id}/complete")->assertForbidden();
        $this->assertSame('pending', $task->fresh()->status);
    }

    public function test_staff_complete_a_task_in_their_own_site(): void
    {
        $this->judge($this->team());
        $task = Task::firstOrFail();

        $staff = User::factory()->create([
            'user_type' => 'staff',
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
        ]);

        $this->actingAs($staff)->post("/staff/tasks/{$task->id}/complete")->assertRedirect();
        $this->assertSame('done', $task->fresh()->status);
        $this->assertSame($staff->user_id, $task->fresh()->staff_id);
    }
}
