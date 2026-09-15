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
use App\Services\BocaWebcastZipBuilder;
use Helium\User;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/**
 * Issue #138 -- the verdict verification gate.
 *
 * The issue asked for a decision: implement BOCA's second opinion for real,
 * or delete the dead answer2_id/judge2_id columns. What landed is neither
 * literally -- the columns are gone, and what replaces them is DOMjudge's
 * `verification_required` gate rather than BOCA's symmetric double
 * judging. The migration's docblock carries the reasoning; this file
 * carries the proof that the gate actually holds.
 *
 * The claim under test is one sentence: while a contest requires
 * verification, a judged run's verdict does not exist as far as anyone
 * competing is concerned -- not in their submission list, not on their
 * submission page, not in the API, not on the scoreboard, not on the
 * dashboard, not on the broadcast, and not as a balloon walking to their
 * desk -- and the moment it is verified it appears with the contest time
 * and penalty it always had.
 *
 * Every test name below says what would break in a real contest, because
 * "test_verification_works" does not tell the next person whether a
 * failure means a team saw a verdict early or a team lost a solve.
 */
class VerdictVerificationTest extends TestCase
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
            'start_time' => now()->subMinutes(120),
            'duration' => 300,
            'is_active' => true,
            'is_public' => true,
            'penalty' => 20,
            'verification_required' => true,
        ]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problem = Problem::factory()->create([
            'contest_id' => $this->contest->id,
            'short_name' => 'A',
            'name' => 'Arvores',
            'is_fake' => false,
        ]);
        $this->language = Language::factory()->create(['contest_id' => $this->contest->id]);
        $this->accepted = Answer::factory()->create(['contest_id' => $this->contest->id, 'is_accepted' => true, 'short_name' => 'AC']);
        $this->wrong = Answer::factory()->create(['contest_id' => $this->contest->id, 'is_accepted' => false, 'short_name' => 'WA']);
    }

    private function team(): User
    {
        return User::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_type' => 'team',
        ]);
    }

    private function judge(): User
    {
        return User::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_type' => 'judge',
        ]);
    }

    /**
     * A run that a judge has already answered but nobody has released.
     * `contest_time` is explicit in every call because the penalty maths is
     * what half of these tests are about.
     */
    private function judgedRun(User $team, bool $accepted, int $contestTime, bool $verified = false): Run
    {
        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $team->user_id,
            'problem_id' => $this->problem->id,
            'language_id' => $this->language->id,
            'status' => 'judged',
            'answer_id' => $accepted ? $this->accepted->id : $this->wrong->id,
            'contest_time' => $contestTime,
            'auto_judge_result' => $accepted ? 'Accepted' : 'Wrong answer on test 3',
            'auto_judge_stdout' => 'saida do julgamento',
            'verified_at' => $verified ? now() : null,
            'verified_by' => null,
        ]);

        Score::updateScore($run);

        return $run;
    }

    private function scoreFor(User $team): ?Score
    {
        return Score::where('contest_id', $this->contest->id)
            ->where('user_id', $team->user_id)
            ->where('problem_id', $this->problem->id)
            ->first();
    }

    // -----------------------------------------------------------------
    // The schema decision
    // -----------------------------------------------------------------

    public function test_the_never_written_second_opinion_columns_are_no_longer_in_the_schema(): void
    {
        // The issue's actual complaint: "schema que promete uma
        // funcionalidade inexistente e pior que ausencia -- quem le a tabela
        // conclui que existe."
        foreach (['answer1_id', 'judge1_id', 'answer2_id', 'judge2_id'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('runs', $column),
                "runs.{$column} is BOCA's second-opinion shape and was never read or written here; it should be gone."
            );
        }

        foreach (['verified_at', 'verified_by', 'verify_comment'] as $column) {
            $this->assertTrue(Schema::hasColumn('runs', $column), "runs.{$column} is what replaces them.");
        }

        $this->assertTrue(Schema::hasColumn('contests', 'verification_required'));
    }

    // -----------------------------------------------------------------
    // Scoreboard correctness -- the risk in this change
    // -----------------------------------------------------------------

    public function test_a_solve_that_no_jury_member_has_released_does_not_appear_on_the_scoreboard(): void
    {
        $team = $this->team();
        $this->judgedRun($team, accepted: true, contestTime: 600);

        $score = $this->scoreFor($team);

        $this->assertFalse(
            (bool) $score->is_solved,
            'An unverified AC was counted as a solve: the scoreboard would announce the verdict the gate is holding back.'
        );
        $this->assertSame(0, (int) $score->attempts, 'Even the attempt count is a signal that a verdict happened.');
    }

    public function test_releasing_a_solve_scores_it_at_the_minute_it_was_submitted_not_the_minute_it_was_released(): void
    {
        $team = $this->team();
        // Submitted at 00:10:00 of the contest; verified an hour later.
        $run = $this->judgedRun($team, accepted: true, contestTime: 600);

        $this->actingAs($this->judge())
            ->post(route('judge.runs.verify', $run))
            ->assertRedirect(route('judge.runs'));

        $score = $this->scoreFor($team);

        $this->assertTrue((bool) $score->is_solved);
        $this->assertSame(
            10,
            (int) $score->solved_time,
            'A released solve must land at the team\'s own submission minute. Scoring it at the verification '
            .'time would penalise the team for how long the jury took to look at it.'
        );
    }

    public function test_a_wrong_answer_released_after_the_solve_still_costs_the_team_its_penalty(): void
    {
        // The ordering bug that made a recompute necessary. Run #1 (WA) is
        // judged but withheld; run #2 (AC) is judged and released first.
        // Folding verdicts into the cell as they arrive leaves #1's attempt
        // uncounted forever, because the cell is already solved by the time
        // #1 is released -- the team keeps a penalty it did not earn, in its
        // own favour, which nobody reports.
        $team = $this->team();
        $judge = $this->judge();

        $wrongRun = $this->judgedRun($team, accepted: false, contestTime: 300);
        $acceptedRun = $this->judgedRun($team, accepted: true, contestTime: 900);

        $this->actingAs($judge)->post(route('judge.runs.verify', $acceptedRun))->assertRedirect();

        $this->assertSame(0, (int) $this->scoreFor($team)->penalty_time, 'Nothing has released the WA yet.');
        $this->assertSame(1, (int) $this->scoreFor($team)->attempts);

        $this->actingAs($judge)->post(route('judge.runs.verify', $wrongRun))->assertRedirect();

        $score = $this->scoreFor($team);

        $this->assertSame(2, (int) $score->attempts, 'The released WA is an attempt and must be counted.');
        $this->assertSame(
            20,
            (int) $score->penalty_time,
            'One failed attempt before the solve is one penalty (contests.penalty = 20). A verdict released out '
            .'of order must still cost what it would have cost in order.'
        );
        $this->assertSame(15, (int) $score->solved_time, 'The solve time itself must not move.');
    }

    public function test_revoking_a_verification_takes_the_solve_back_off_the_scoreboard(): void
    {
        $team = $this->team();
        $judge = $this->judge();
        $run = $this->judgedRun($team, accepted: true, contestTime: 600);

        $this->actingAs($judge)->post(route('judge.runs.verify', $run))->assertRedirect();
        $this->assertTrue((bool) $this->scoreFor($team)->is_solved);

        $this->actingAs($judge)->post(route('judge.runs.unverify', $run))->assertRedirect();

        $score = $this->scoreFor($team);

        $this->assertFalse(
            (bool) $score->is_solved,
            'A verification given in error has to be revocable, standings included -- otherwise the only way back '
            .'is a rejudge that throws away a correct verdict.'
        );
        $this->assertFalse((bool) $score->is_first_solver, 'A withdrawn solve must not keep the first-solve claim.');
        $this->assertSame(0, (int) $score->attempts);
    }

    /**
     * Judging order versus submission order, with the gate OFF.
     *
     * This is the one place the recompute does NOT reproduce what the
     * accumulating version produced, and it is worth pinning because the
     * old answer was wrong. Old behaviour: the first run to be JUDGED won
     * the cell, and once solved it returned early, so a wrong answer
     * submitted earlier but judged later never cost its penalty. New
     * behaviour: the cell is a function of the runs ordered by
     * contest_time, which is what ICPC counts.
     *
     * Not an exotic case. Judging out of submission order is what happens
     * on a rejudge, on a run a human judges by hand, and -- routinely --
     * whenever more than one judgehost is pulling work (#53).
     */
    public function test_a_wrong_answer_judged_after_the_solve_still_costs_its_penalty(): void
    {
        $this->contest->update(['verification_required' => false]);

        $team = $this->team();

        // Submitted second, judged first.
        $this->judgedRun($team, accepted: true, contestTime: 1200);

        $solvedOnly = $this->scoreFor($team);
        $this->assertSame(1, (int) $solvedOnly->attempts);
        $this->assertSame(0, (int) $solvedOnly->penalty_time);

        // Submitted FIRST, judged second. The accumulating version returned
        // early here because the cell was already solved, and this attempt
        // -- and its 20 minutes -- vanished.
        $this->judgedRun($team, accepted: false, contestTime: 600);

        $score = $this->scoreFor($team);

        $this->assertSame(2, (int) $score->attempts, 'the earlier wrong answer was not counted');
        $this->assertSame(20, (int) $score->penalty_time, 'the team kept a penalty it had not earned');
        $this->assertTrue((bool) $score->is_solved);
        $this->assertSame(20, (int) $score->solved_time, 'the solve time moved; it is the AC that sets it');
    }

    /**
     * The other place the recompute changes an answer with the gate off,
     * and again the old one was wrong: `if ($score->is_solved) return;` made
     * a solved cell permanent. A judge who rejudged an accepted run to
     * WRONG ANSWER -- which is the whole reason rejudging exists -- left the
     * team solved on the scoreboard for the rest of the contest.
     */
    public function test_rejudging_an_accepted_run_to_wrong_answer_takes_the_solve_away(): void
    {
        $this->contest->update(['verification_required' => false]);

        $team = $this->team();
        $run = $this->judgedRun($team, accepted: true, contestTime: 600);

        $this->assertTrue((bool) $this->scoreFor($team)->is_solved);

        $run->update(['answer_id' => $this->wrong->id]);
        Score::updateScore($run->fresh());

        $score = $this->scoreFor($team);

        $this->assertFalse((bool) $score->is_solved, 'the cell stayed solved after the AC was taken away');
        $this->assertSame(0, (int) $score->penalty_time);
        $this->assertFalse((bool) $score->is_first_solver, 'the first-solve claim outlived the solve');
    }

    public function test_with_verification_switched_off_a_judged_run_scores_immediately_as_it_always_did(): void
    {
        // The gate is opt-in per contest. A practice contest, or any event
        // that did not ask for it, must behave exactly as before this
        // change -- including counting a judged run with verified_at null.
        $this->contest->update(['verification_required' => false]);

        $team = $this->team();
        $this->judgedRun($team, accepted: false, contestTime: 300);
        $this->judgedRun($team, accepted: true, contestTime: 900);

        $score = $this->scoreFor($team);

        $this->assertTrue((bool) $score->is_solved);
        $this->assertSame(2, (int) $score->attempts);
        $this->assertSame(15, (int) $score->solved_time);
        $this->assertSame(20, (int) $score->penalty_time);
    }

    public function test_no_balloon_walks_to_the_desk_before_the_verdict_is_released(): void
    {
        // A balloon carried across the contest hall is the loudest verdict
        // announcement there is, and it cannot be taken back.
        $team = $this->team();
        $run = $this->judgedRun($team, accepted: true, contestTime: 600);

        $this->assertSame(
            0,
            Task::where('contest_id', $this->contest->id)->where('user_id', $team->user_id)->count(),
            'A balloon was dispatched for a verdict the team is not allowed to know about yet.'
        );

        $this->actingAs($this->judge())->post(route('judge.runs.verify', $run))->assertRedirect();

        $this->assertSame(
            1,
            Task::where('contest_id', $this->contest->id)->where('user_id', $team->user_id)->count(),
            'Releasing the verdict must also release the balloon; otherwise the team is solved on the board with '
            .'no balloon on the desk and the staff queue never learns about it.'
        );
    }

    // -----------------------------------------------------------------
    // Team-visible read paths
    // -----------------------------------------------------------------

    public function test_the_teams_own_submission_list_shows_a_withheld_run_as_still_being_evaluated(): void
    {
        $team = $this->team();
        $this->judgedRun($team, accepted: true, contestTime: 600);

        $response = $this->actingAs($team)->get('/submissions');

        $response->assertOk();

        // Asserted on the view data rather than the rendered HTML because
        // this page carries a verdict legend that spells out every code --
        // assertDontSee('AC') passes or fails on the legend, not on the row.
        $row = $response->viewData('submissions')->first();

        $this->assertNull($row->result, 'The team was shown the verdict of a run nobody has released.');
        $this->assertSame('judging', $row->status);
        $this->assertSame(0, $response->viewData('acceptedCount'), '"1 aceita" announces the verdict just as well.');

        $response->assertSee('Em avaliação');
    }

    public function test_the_teams_own_submission_page_hides_the_verdict_and_the_judging_output(): void
    {
        // The detail page is the richest leak: the verdict name, the status
        // word, and auto_judge_result/stdout, which routinely spell the
        // verdict out in prose.
        $team = $this->team();
        $run = $this->judgedRun($team, accepted: false, contestTime: 600);

        $response = $this->actingAs($team)->get("/submission/{$run->id}");

        $response->assertOk();
        $response->assertDontSee($this->wrong->name);
        $response->assertDontSee('Wrong answer on test 3');
        $response->assertDontSee('saida do julgamento');
        $response->assertSee('Em avaliação');
    }

    public function test_the_runs_api_does_not_hand_a_team_its_own_withheld_verdict(): void
    {
        $team = $this->team();
        $this->judgedRun($team, accepted: true, contestTime: 600);

        Sanctum::actingAs($team);
        $response = $this->getJson('/api/runs');

        $response->assertOk();
        $row = $response->json('data.0');

        $this->assertNull($row['answer_id'], 'answer_id alone identifies the verdict.');
        $this->assertNull($row['answer']);
        $this->assertSame('judging', $row['status'], 'A team must not even learn that judging has finished.');
    }

    public function test_the_run_detail_api_strips_every_field_that_would_give_a_withheld_verdict_away(): void
    {
        // Hiding the `answer` relation is not hiding the verdict: five other
        // attributes on the same row answer the same question.
        $team = $this->team();
        $run = $this->judgedRun($team, accepted: true, contestTime: 600);
        $run->update(['judged_time' => 700, 'judge_id' => $this->judge()->user_id]);

        Sanctum::actingAs($team);
        $response = $this->getJson("/api/runs/{$run->id}");

        $response->assertOk();

        foreach (['answer_id', 'judged_time', 'judge_id', 'auto_judge_result', 'auto_judge_stdout', 'verified_at'] as $field) {
            $this->assertNull($response->json($field), "{$field} still reveals the withheld verdict.");
        }

        $this->assertNull($response->json('answer'));
        $this->assertSame('judging', $response->json('status'));
    }

    /**
     * `/` and `/home` are the same HomeController::index(), but only
     * `/home` carries `auth` (routes/web.php:39 vs :41). So `/` is the
     * widest verdict disclosure on the site -- no session at all -- and it
     * lists OTHER teams' runs, which is how a withheld verdict reaches the
     * team it belongs to by way of the projector in the hall.
     *
     * Asserted on view data rather than on the rendered page: the dashboard
     * renders a legend that contains the string "AC" whether or not any run
     * has that verdict, so assertDontSee('AC') would fail on a page that is
     * hiding the verdict perfectly well.
     */
    public function test_the_public_dashboard_does_not_announce_another_teams_withheld_verdict(): void
    {
        $team = $this->team();
        $run = $this->judgedRun($team, accepted: true, contestTime: 600);

        $listed = collect($this->get('/')->assertOk()->viewData('recentSubmissions'))
            ->firstWhere('id', $run->id);

        $this->assertNotNull($listed, 'the run vanished from the dashboard entirely, which is not the ask');
        $this->assertNull($listed->result, 'another team\'s withheld verdict was on the public dashboard');
        $this->assertSame('judging', $listed->status);

        // And once verified it is announced, because withholding for ever
        // is just as wrong as announcing early.
        $this->actingAs($this->judge())->post(route('judge.runs.verify', $run))->assertRedirect();

        $this->assertSame(
            'AC',
            collect($this->get('/')->viewData('recentSubmissions'))->firstWhere('id', $run->id)->result
        );
    }

    /**
     * The judged program's own output is the verdict, for anyone who can
     * read it: a wrong answer's stdout is the wrong answer. The dashboard
     * selects `runs.*`, so it rides along unless something clears it.
     */
    public function test_the_public_dashboard_does_not_leak_the_judging_output_either(): void
    {
        $team = $this->team();
        $run = $this->judgedRun($team, accepted: false, contestTime: 600);

        $listed = collect($this->get('/')->viewData('recentSubmissions'))->firstWhere('id', $run->id);

        $this->assertNull($listed->auto_judge_result);
        $this->assertNull($listed->auto_judge_stdout, 'the judged output gave the withheld verdict away');
        $this->assertNull($listed->auto_judge_stderr);
    }

    /**
     * A balloon is the loudest verdict announcement there is, and the
     * recompute made a new cycle reachable: the cell can now go solved ->
     * unsolved -> solved, which the accumulating version could never do
     * because it returned early once solved. Raised in review as a possible
     * double-award. It is not one -- BalloonService::awardFor() already
     * de-dupes by (contest, team, problem) inside a locked transaction --
     * but the path is new, so the guarantee is pinned here rather than left
     * resting on a file nobody will think to re-read.
     */
    public function test_verifying_twice_does_not_send_a_second_balloon_to_the_desk(): void
    {
        $team = $this->team();
        $run = $this->judgedRun($team, accepted: true, contestTime: 600);

        $this->actingAs($this->judge())->post(route('judge.runs.verify', $run))->assertRedirect();
        $this->assertSame(1, Task::where('user_id', $team->user_id)->where('is_system', true)->count());

        $this->actingAs($this->judge())->post(route('judge.runs.unverify', $run))->assertRedirect();
        $this->actingAs($this->judge())->post(route('judge.runs.verify', $run))->assertRedirect();

        $this->assertSame(
            1,
            Task::where('user_id', $team->user_id)->where('is_system', true)->count(),
            'the team got a second balloon for the same solve'
        );
    }

    public function test_the_public_dashboard_accepted_counter_does_not_tick_up_on_a_withheld_solve(): void
    {
        $team = $this->team();
        $run = $this->judgedRun($team, accepted: true, contestTime: 600);

        $this->assertSame(0, $this->get('/')->viewData('acceptedSubmissions'));

        $this->actingAs($this->judge())->post(route('judge.runs.verify', $run))->assertRedirect();

        $this->assertSame(
            1,
            $this->get('/')->viewData('acceptedSubmissions'),
            '"Somebody just solved something" is most of a verdict, and this figure is public.'
        );
    }

    public function test_the_webcast_export_reports_a_withheld_run_as_not_yet_judged(): void
    {
        // Exporting is admin-only; the artifact is not. It feeds a public
        // animator, which is a screen in the contest hall.
        $team = $this->team();
        $run = $this->judgedRun($team, accepted: true, contestTime: 600);

        $this->assertSame('?', $this->webcastResultFor($run), 'The broadcast carried an unreleased verdict.');

        $this->actingAs($this->judge())->post(route('judge.runs.verify', $run))->assertRedirect();

        $this->assertSame('Y', $this->webcastResultFor($run->fresh()));
    }

    /**
     * The `result` field of this run's line in a freshly built webcast ZIP.
     * BOCA's format is FS-separated (byte 0x1C); the run line is
     * id, minutes, team_id, problem_letter, result.
     */
    private function webcastResultFor(Run $run): string
    {
        $path = app(BocaWebcastZipBuilder::class)->build($this->contest->fresh());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $runsFile = $zip->getFromName('runs');
        $zip->close();
        @unlink($path);

        foreach (explode("\n", trim((string) $runsFile)) as $line) {
            $fields = explode(BocaWebcastZipBuilder::FS, $line);

            if (($fields[0] ?? null) === (string) $run->id) {
                return $fields[4] ?? '';
            }
        }

        $this->fail("Run #{$run->id} is missing from the webcast export entirely.");
    }

    // -----------------------------------------------------------------
    // Staff keep seeing everything
    // -----------------------------------------------------------------

    public function test_a_judge_still_sees_the_withheld_verdict_the_team_cannot(): void
    {
        // The gate must not blind the people whose job is to release it.
        $team = $this->team();
        $run = $this->judgedRun($team, accepted: true, contestTime: 600);

        Sanctum::actingAs($this->judge());
        $response = $this->getJson("/api/runs/{$run->id}");

        $response->assertOk();
        $this->assertSame($this->accepted->id, $response->json('answer_id'));
        $this->assertSame('judged', $response->json('status'));
        $this->assertSame('Accepted', $response->json('auto_judge_result'));
    }

    public function test_the_judge_screen_lists_the_runs_whose_verdicts_are_still_being_withheld(): void
    {
        // Without this list, a gated contest silently accumulates runs that
        // are done but invisible, and nobody is looking at a queue that
        // says so.
        $team = $this->team();
        $withheld = $this->judgedRun($team, accepted: true, contestTime: 600);
        $released = $this->judgedRun($team, accepted: false, contestTime: 300, verified: true);

        $response = $this->actingAs($this->judge())->get('/judge/runs');

        $response->assertOk();

        $awaiting = $response->viewData('awaitingVerification')->pluck('id')->all();

        $this->assertContains($withheld->id, $awaiting);
        $this->assertNotContains($released->id, $awaiting);
        $response->assertSee('Aguardando verificação');
    }

    // -----------------------------------------------------------------
    // What verifying may and may not do
    // -----------------------------------------------------------------

    public function test_a_verifier_cannot_change_the_verdict_while_releasing_it(): void
    {
        // DOMjudge's verifyAction writes verified / jury_member /
        // verify_comment and nothing else; a jury member who disagrees
        // rejudges. Accepting an answer_id here would produce the one
        // artifact an appeal cannot work with: a verdict with two authors
        // and a record of only one.
        $team = $this->team();
        $run = $this->judgedRun($team, accepted: false, contestTime: 600);

        Sanctum::actingAs($this->judge());
        $response = $this->putJson("/api/runs/{$run->id}/verify", [
            'answer_id' => $this->accepted->id,
            'verify_comment' => 'conferido a mao',
        ]);

        $response->assertOk();

        $run->refresh();

        $this->assertSame(
            $this->wrong->id,
            $run->answer_id,
            'Verifying overwrote the verdict. The second pair of eyes confirms or rejudges; it never edits in place.'
        );
        $this->assertNotNull($run->verified_at);
        $this->assertSame('conferido a mao', $run->verify_comment);
        $this->assertFalse((bool) $this->scoreFor($team)->is_solved, 'The released verdict is still the WA.');
    }

    public function test_rejudging_clears_an_earlier_verification_so_the_next_verdict_is_not_pre_approved(): void
    {
        // Otherwise a verdict produced later, possibly against different
        // test data, arrives already carrying a jury member's signature it
        // was never shown to.
        $team = $this->team();
        $run = $this->judgedRun($team, accepted: true, contestTime: 600, verified: true);
        $run->update(['verified_by' => $this->judge()->user_id, 'verify_comment' => 'ok']);

        Sanctum::actingAs($this->judge());
        $this->postJson("/api/runs/{$run->id}/rejudge")->assertOk();

        $run->refresh();

        $this->assertNull($run->verified_at);
        $this->assertNull($run->verified_by);
        $this->assertNull($run->verify_comment);
    }

    public function test_a_run_with_no_verdict_yet_cannot_be_marked_verified(): void
    {
        $team = $this->team();

        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $team->user_id,
            'problem_id' => $this->problem->id,
            'language_id' => $this->language->id,
            'status' => 'pending',
            'answer_id' => null,
            'contest_time' => 600,
        ]);

        Sanctum::actingAs($this->judge());
        $this->putJson("/api/runs/{$run->id}/verify")->assertStatus(422);

        $this->assertNull($run->fresh()->verified_at, 'A pre-approved pending run would publish its verdict unread.');
    }

    public function test_a_team_token_cannot_release_its_own_withheld_verdict(): void
    {
        $team = $this->team();
        $run = $this->judgedRun($team, accepted: true, contestTime: 600);

        Sanctum::actingAs($team);
        $this->putJson("/api/runs/{$run->id}/verify")->assertStatus(403);

        $this->assertNull($run->fresh()->verified_at);
    }
}
