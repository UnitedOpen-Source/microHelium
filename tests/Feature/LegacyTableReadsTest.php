<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Services\Practice\PracticeContest;
use Helium\User;
use Tests\TestCase;

/**
 * Issue #106 -- the dashboard counted `exercises` and `teams`, and the
 * clarification form filled its problem dropdown from `exercises`. Those are
 * the 2017 Helium tables; a contest created through the wizard writes to
 * `problems` and `users` and never touches them.
 */
class LegacyTableReadsTest extends TestCase
{
    private function contestWith(int $problems, int $teams): Contest
    {
        $contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()->subMinutes(5)]);
        $site = Site::factory()->create(['contest_id' => $contest->id]);

        for ($i = 1; $i <= $problems; $i++) {
            Problem::factory()->create([
                'contest_id' => $contest->id,
                'short_name' => chr(64 + $i),
                'name' => 'Problema '.$i,
                'sort_order' => $i,
            ]);
        }

        for ($i = 1; $i <= $teams; $i++) {
            User::factory()->create([
                'contest_id' => $contest->id,
                'site_id' => $site->id,
                'user_type' => 'team',
            ]);
        }

        return $contest;
    }

    public function test_the_dashboard_counts_the_contests_real_problems_and_teams(): void
    {
        $contest = $this->contestWith(problems: 3, teams: 4);

        $response = $this->get('/')->assertOk();

        $this->assertSame(3, $response->viewData('totalProblems'));
        $this->assertSame(4, $response->viewData('totalTeams'));
    }

    public function test_the_dashboard_numbers_all_describe_the_same_contest(): void
    {
        $contest = $this->contestWith(problems: 2, teams: 1);
        $other = $this->contestWith(problems: 5, teams: 5);
        $other->update(['is_active' => false]);

        $site = $contest->sites()->firstOrFail();
        $team = $contest->users()->where('user_type', 'team')->firstOrFail();
        $language = Language::factory()->create(['contest_id' => $contest->id]);
        $accepted = Answer::factory()->create(['contest_id' => $contest->id, 'is_accepted' => true]);

        Run::factory()->create([
            'contest_id' => $contest->id, 'site_id' => $site->id, 'user_id' => $team->user_id,
            'problem_id' => $contest->problems()->firstOrFail()->id, 'language_id' => $language->id,
            'status' => 'judged', 'answer_id' => $accepted->id,
        ]);
        Run::factory()->create([
            'contest_id' => $other->id, 'site_id' => $other->sites()->firstOrFail()->id,
            'user_id' => $other->users()->where('user_type', 'team')->firstOrFail()->user_id,
            'problem_id' => $other->problems()->firstOrFail()->id,
            'language_id' => Language::factory()->create(['contest_id' => $other->id])->id,
            'status' => 'judged',
        ]);

        $response = $this->get('/')->assertOk();

        // One submission, from this contest -- not the other contest's too.
        $this->assertSame(1, $response->viewData('totalSubmissions'));
        $this->assertSame(2, $response->viewData('totalProblems'));
    }

    public function test_the_dashboard_survives_a_judged_submission(): void
    {
        $contest = $this->contestWith(problems: 1, teams: 1);
        $site = $contest->sites()->firstOrFail();
        $team = $contest->users()->where('user_type', 'team')->firstOrFail();
        $language = Language::factory()->create(['contest_id' => $contest->id]);
        $accepted = Answer::factory()->create(['contest_id' => $contest->id, 'is_accepted' => true]);

        Run::factory()->create([
            'contest_id' => $contest->id, 'site_id' => $site->id, 'user_id' => $team->user_id,
            'problem_id' => $contest->problems()->firstOrFail()->id, 'language_id' => $language->id,
            'status' => 'judged', 'answer_id' => $accepted->id,
            'auto_judge_start' => now()->subSeconds(3), 'auto_judge_end' => now(),
        ]);

        // The "Tempo" column renders $submission->time, and nothing ever
        // selected one: the dashboard threw "Undefined property:
        // stdClass::$time" as soon as a contest had its first submission.
        $response = $this->get('/')->assertOk();

        $this->assertSame(3, collect($response->viewData('recentSubmissions'))->firstOrFail()->time);
    }

    public function test_the_dashboard_never_describes_the_practice_contest(): void
    {
        $practice = app(PracticeContest::class)->contest();
        Problem::factory()->count(3)->create(['contest_id' => $practice->id]);

        // Issue #43: practice is not an event.
        $response = $this->get('/')->assertOk();

        $this->assertSame(0, $response->viewData('totalProblems'));
    }

    public function test_an_empty_install_still_renders(): void
    {
        $this->get('/')->assertOk()->assertViewHas('totalProblems', 0);
    }

    // --- clarifications --------------------------------------------------

    public function test_a_team_can_pick_a_real_problem_in_the_clarification_form(): void
    {
        $contest = $this->contestWith(problems: 2, teams: 1);
        $team = $contest->users()->where('user_type', 'team')->firstOrFail();

        $response = $this->actingAs($team)->get('/clarifications')->assertOk();

        $problems = $response->viewData('problems');
        $this->assertCount(2, $problems);
        // The letter and name, from `problems` -- the dropdown used to be
        // empty, so "Geral" was the only thing a team could ever ask about.
        $response->assertSeeText('A - Problema 1');
    }

    public function test_a_clarification_about_a_problem_stores_that_problems_id(): void
    {
        $contest = $this->contestWith(problems: 2, teams: 1);
        $team = $contest->users()->where('user_type', 'team')->firstOrFail();
        $problem = $contest->problems()->orderBy('sort_order')->firstOrFail();

        $this->actingAs($team)->post('/clarifications', [
            'question' => 'O limite vale por caso de teste?',
            'problem_id' => $problem->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('clarifications', [
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
        ]);
    }

    public function test_a_problem_from_another_contest_is_discarded_rather_than_stored(): void
    {
        $contest = $this->contestWith(problems: 1, teams: 1);
        $other = $this->contestWith(problems: 1, teams: 1);
        $other->update(['is_active' => false]);
        $team = $contest->users()->where('user_type', 'team')->firstOrFail();

        $this->actingAs($team)->post('/clarifications', [
            'question' => 'Pergunta',
            'problem_id' => $other->problems()->firstOrFail()->id,
        ])->assertRedirect();

        // clarifications.problem_id is a foreign key to `problems`; an id
        // from anywhere must not reach it.
        $this->assertDatabaseHas('clarifications', [
            'contest_id' => $contest->id,
            'problem_id' => null,
        ]);
    }

    public function test_the_listing_shows_the_problem_letter_not_a_raw_id(): void
    {
        $contest = $this->contestWith(problems: 1, teams: 1);
        $team = $contest->users()->where('user_type', 'team')->firstOrFail();
        $problem = $contest->problems()->firstOrFail();

        $this->actingAs($team)->post('/clarifications', [
            'question' => 'Uma pergunta',
            'problem_id' => $problem->id,
        ]);

        $response = $this->actingAs($team)->get('/clarifications')->assertOk();
        $clarification = collect($response->viewData('clarifications'))->firstOrFail();

        $this->assertSame('A - Problema 1', $clarification->problem);
    }
}
