<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Score;
use App\Models\Site;
use App\Models\SiteJudgingRoute;
use App\Services\IcpcReportBuilder;
use App\Services\Practice\PracticeContest;
use Helium\User;
use Tests\TestCase;

/**
 * Issue #144 -- the per-site report (BOCA's src/staff/report/) and the judge
 * history (BOCA's src/judge/history.php).
 *
 * Two things are worth testing here and they are not "the endpoint returns
 * 200".
 *
 * The first is who the numbers reach. Both reports are deliberately
 * unfrozen: their audience is the organisation, not the room. That is
 * precisely the shape of the bug issue #134 found in scoreboard/export, and
 * the only thing standing between this feature and the same bug is that a
 * team cannot reach it and a coordinator at site B cannot reach site A's
 * room by editing a query string. Both are asserted from both ends -- the
 * refusal, and the fact that another site's rows are genuinely absent from
 * the body rather than merely unlinked.
 *
 * The second is that the aggregates are right. A chart is read as fact at a
 * glance, and a wrong bar is worse than no bar, so the counts, the buckets
 * and the timings are pinned against a fixture whose every run is written
 * out below.
 */
class ContestReportTest extends TestCase
{
    private Contest $contest;

    private Site $siteA;

    private Site $siteB;

    private Problem $problemA;

    private Problem $problemB;

    private Problem $problemC;

    private Answer $accepted;

    private Answer $wrong;

    private Language $language;

    private User $alfa;

    private User $beta;

    private User $gama;

    private User $judge;

    /**
     * The fixture, in full.
     *
     * Site A (two teams):
     *   alfa  problem A  minute 10  Wrong Answer   judged 30 s later, by $judge
     *   alfa  problem A  minute 20  Accepted       judged 120 s later, by $judge
     *   beta  problem B  minute 40  Accepted       judged 60 s later, auto-judged
     *   beta  problem A  minute 50  still queued
     * Site B (one team):
     *   gama  problem A  minute 5   Accepted       judged 15 s later, by $judge
     *
     * Problem C exists and nobody ever submits to it -- "teve problema que
     * ninguem resolveu?" is one of the questions the issue names, and it can
     * only be answered by a problem that is still listed with a zero.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create([
            'start_time' => now()->subMinutes(120),
            'duration' => 300,
            'penalty' => 20,
            'is_active' => true,
        ]);

        $this->siteA = Site::factory()->create(['contest_id' => $this->contest->id, 'name' => 'Sede Alfa']);
        $this->siteB = Site::factory()->create(['contest_id' => $this->contest->id, 'name' => 'Sede Beta']);

        $this->problemA = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'A', 'name' => 'Somatorio', 'sort_order' => 1]);
        $this->problemB = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'B', 'name' => 'Grafos', 'sort_order' => 2]);
        $this->problemC = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'C', 'name' => 'Intratavel', 'sort_order' => 3]);

        $this->language = Language::factory()->create(['contest_id' => $this->contest->id]);
        $this->accepted = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'AC', 'name' => 'Accepted', 'is_accepted' => true, 'sort_order' => 1]);
        $this->wrong = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'WA', 'name' => 'Wrong Answer', 'is_accepted' => false, 'sort_order' => 2]);

        $this->judge = $this->account('judge', null);
        $this->alfa = $this->account('team', $this->siteA, 'Equipe Alfa');
        $this->beta = $this->account('team', $this->siteA, 'Equipe Beta');
        $this->gama = $this->account('team', $this->siteB, 'Equipe Gama');

        $this->judged($this->alfa, $this->siteA, $this->problemA, 10, $this->wrong, 30, $this->judge);
        $this->judged($this->alfa, $this->siteA, $this->problemA, 20, $this->accepted, 120, $this->judge);
        $this->judged($this->beta, $this->siteA, $this->problemB, 40, $this->accepted, 60, null);
        $this->queued($this->beta, $this->siteA, $this->problemA, 50);
        $this->judged($this->gama, $this->siteB, $this->problemA, 5, $this->accepted, 15, $this->judge);
    }

    private function account(string $type, ?Site $site, ?string $name = null): User
    {
        $suffix = $this->uniqueSuffix();

        return User::factory()->create([
            'user_type' => $type,
            'contest_id' => $this->contest->id,
            'site_id' => $site?->id,
            'fullname' => $name ?? ucfirst($type).' '.$suffix,
        ]);
    }

    private function judged(User $team, Site $site, Problem $problem, int $minute, Answer $answer, int $delaySeconds, ?User $judge): Run
    {
        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $site->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'language_id' => $this->language->id,
            'status' => 'judged',
            'answer_id' => $answer->id,
            'contest_time' => $minute * 60,
            'judged_time' => $minute * 60 + $delaySeconds,
            'judge_id' => $judge?->user_id,
            'judge_site_id' => $judge?->site_id,
        ]);

        Score::updateScore($run);

        return $run;
    }

    private function queued(User $team, Site $site, Problem $problem, int $minute): Run
    {
        return Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $site->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'language_id' => $this->language->id,
            'status' => 'pending',
            'answer_id' => null,
            'judged_time' => null,
            'contest_time' => $minute * 60,
        ]);
    }

    // --- the gate ---------------------------------------------------------

    public function test_a_team_is_refused_both_reports(): void
    {
        // Unfrozen verdicts, every team's name, and the judging queue depth:
        // exactly the material the freeze exists to keep away from the room.
        $this->actingAs($this->alfa);

        $this->getJson('/api/frontend/reports/site')->assertForbidden();
        $this->getJson('/api/frontend/reports/judge-history')->assertForbidden();
        $this->get('/staff/report')->assertForbidden();
        $this->get('/judge/history')->assertForbidden();
    }

    public function test_an_anonymous_visitor_is_refused_both_reports(): void
    {
        $this->getJson('/api/frontend/reports/site')->assertUnauthorized();
        $this->getJson('/api/frontend/reports/judge-history')->assertUnauthorized();
    }

    public function test_the_site_report_is_for_the_organisation_not_the_jury(): void
    {
        // A judge rules on problems; they do not run a room, and BOCA files
        // this report under staff/ rather than judge/.
        $this->actingAs($this->judge)->getJson('/api/frontend/reports/site')->assertForbidden();

        foreach ([$this->account('staff', $this->siteA), $this->account('site', $this->siteA)] as $user) {
            $this->actingAs($user)->getJson('/api/frontend/reports/site')->assertOk();
        }
    }

    public function test_the_judge_history_is_for_the_jury_not_the_room(): void
    {
        // A verdict is the jury's, and this screen carries every team name
        // next to every unfrozen verdict.
        foreach ([$this->account('staff', $this->siteA), $this->account('site', $this->siteA)] as $user) {
            $this->actingAs($user)->getJson('/api/frontend/reports/judge-history')->assertForbidden();
        }

        $this->actingAs($this->judge)->getJson('/api/frontend/reports/judge-history')->assertOk();
        $this->actingAs($this->createAdminUser())->getJson('/api/frontend/reports/judge-history')->assertOk();
    }

    public function test_the_practice_contest_has_no_report(): void
    {
        // Issue #43: practice is not an event. It has no sites, no jury and
        // nothing to report on, and must not be reachable through an
        // event-shaped surface.
        $practice = app(PracticeContest::class)->contest();

        $this->actingAs($this->createAdminUser())
            ->getJson('/api/frontend/reports/site?contest_id='.$practice->id)
            ->assertNotFound();
    }

    // --- the site scoping -------------------------------------------------

    public function test_a_coordinator_of_another_site_sees_nothing_of_this_one(): void
    {
        $coordinator = $this->account('site', $this->siteB);

        $data = $this->actingAs($coordinator)->getJson('/api/frontend/reports/site')
            ->assertOk()->json('data');

        // Site B has exactly one run, by Equipe Gama, accepted at minute 5.
        $this->assertSame($this->siteB->id, $data['scope']['site_id']);
        $this->assertSame('Sede Beta', $data['scope']['site_name']);
        $this->assertSame(1, $data['summary']['submissions']);
        $this->assertSame(1, $data['summary']['teams']);
        $this->assertSame(0, $data['summary']['pending']);

        // Site A's four runs are absent, not merely unlinked: problem B was
        // only ever submitted to from site A.
        $problemB = collect($data['problems'])->firstWhere('short_name', 'B');
        $this->assertSame(0, $problemB['submissions']);
        $this->assertSame(0, $problemB['solved_teams']);

        // And the site picker is not a directory of every room either.
        $this->assertFalse($data['scope']['can_choose_site']);
        $this->assertSame([['id' => $this->siteB->id, 'name' => 'Sede Beta']], $data['scope']['sites']);
    }

    public function test_a_coordinator_cannot_reach_another_site_by_naming_it(): void
    {
        // The listing scopes, so the parameter has to as well -- otherwise
        // the scoping is a UI default and not a rule.
        $this->actingAs($this->account('site', $this->siteB))
            ->getJson('/api/frontend/reports/site?site_id='.$this->siteA->id)
            ->assertForbidden();
    }

    public function test_a_site_id_from_another_contest_is_not_found_rather_than_forbidden(): void
    {
        $otherContest = Contest::factory()->create(['is_active' => false]);
        $otherSite = Site::factory()->create(['contest_id' => $otherContest->id]);

        // 404, not 403: answering 403 would confirm that the id names a real
        // site belonging to some other competition.
        $this->actingAs($this->createAdminUser())
            ->getJson('/api/frontend/reports/site?site_id='.$otherSite->id)
            ->assertNotFound();
    }

    public function test_a_coordinator_cannot_report_on_another_contest(): void
    {
        $otherContest = Contest::factory()->create(['is_active' => false]);

        $this->actingAs($this->account('site', $this->siteA))
            ->getJson('/api/frontend/reports/site?contest_id='.$otherContest->id)
            ->assertForbidden();
    }

    public function test_an_admin_sees_every_site_at_once_and_may_cut_to_one(): void
    {
        $admin = $this->createAdminUser();

        $all = $this->actingAs($admin)->getJson('/api/frontend/reports/site')->assertOk()->json('data');
        $this->assertNull($all['scope']['site_id']);
        $this->assertTrue($all['scope']['can_choose_site']);
        $this->assertSame(5, $all['summary']['submissions']);
        $this->assertSame(3, $all['summary']['teams']);

        $onlyA = $this->actingAs($admin)->getJson('/api/frontend/reports/site?site_id='.$this->siteA->id)
            ->assertOk()->json('data');
        $this->assertSame(4, $onlyA['summary']['submissions']);
    }

    public function test_a_judge_stationed_at_a_site_only_sees_that_sites_history(): void
    {
        $siteJudge = $this->account('judge', $this->siteB);

        $data = $this->actingAs($siteJudge)->getJson('/api/frontend/reports/judge-history')
            ->assertOk()->json('data');

        // Site B holds exactly one judged run, Equipe Gama's.
        $this->assertSame(1, $data['meta']['total']);
        $this->assertSame('Equipe Gama', $data['items'][0]['team']);
        $this->assertSame([$this->siteB->id], $data['scope']['site_ids']);
    }

    public function test_an_explicit_judging_route_widens_a_judges_history_the_same_way_it_widens_the_queue(): void
    {
        // Backend\SiteController's judging routes are what let one site's
        // jury handle another's runs; the history has to follow the same
        // list JudgeController::index() scopes the queue by, or a judge can
        // judge a run they then cannot look up.
        SiteJudgingRoute::create(['host_site_id' => $this->siteB->id, 'source_site_id' => $this->siteA->id]);

        $data = $this->actingAs($this->account('judge', $this->siteB))
            ->getJson('/api/frontend/reports/judge-history')->assertOk()->json('data');

        // Site A's two manual judgments plus site B's one. The fourth
        // judged run in that scope was decided by the auto-judge, so it is
        // counted in the summary and kept out of the per-judge listing.
        $this->assertSame(3, $data['meta']['total']);
        $this->assertSame(4, $data['summary']['judged']);
    }

    // --- the numbers ------------------------------------------------------

    public function test_the_site_summary_counts_and_timings_match_the_fixture(): void
    {
        $data = $this->actingAs($this->account('site', $this->siteA))
            ->getJson('/api/frontend/reports/site')->assertOk()->json('data');

        $summary = $data['summary'];

        $this->assertSame(4, $summary['submissions']);
        $this->assertSame(3, $summary['judged']);
        $this->assertSame(1, $summary['pending']);
        $this->assertSame(2, $summary['accepted']);
        $this->assertSame(1, $summary['rejected']);
        $this->assertSame(66.7, $summary['acceptance_rate']);
        $this->assertSame(2, $summary['teams']);
        // Delays of 30, 60 and 120 seconds.
        $this->assertSame(60, $summary['median_delay_seconds']);
        $this->assertSame(120, $summary['max_delay_seconds']);
    }

    public function test_an_unjudged_scope_reports_no_rate_rather_than_zero_percent(): void
    {
        // The specs are explicit that zero must never stand in for a
        // statistic that is not known yet -- a 0% acceptance rate and "no
        // verdicts yet" are opposite pieces of news.
        $emptyContest = Contest::factory()->create(['is_active' => false]);
        $emptySite = Site::factory()->create(['contest_id' => $emptyContest->id]);
        Run::factory()->create([
            'contest_id' => $emptyContest->id,
            'site_id' => $emptySite->id,
            'status' => 'pending',
            'answer_id' => null,
            'judged_time' => null,
            'contest_time' => 60,
        ]);

        $data = $this->actingAs($this->createAdminUser())
            ->getJson('/api/frontend/reports/site?contest_id='.$emptyContest->id)
            ->assertOk()->json('data');

        $this->assertSame(1, $data['summary']['submissions']);
        $this->assertNull($data['summary']['acceptance_rate']);
        $this->assertNull($data['summary']['median_delay_seconds']);
    }

    public function test_every_problem_is_listed_including_the_one_nobody_touched(): void
    {
        $problems = collect($this->actingAs($this->account('site', $this->siteA))
            ->getJson('/api/frontend/reports/site')->assertOk()->json('data.problems'))
            ->keyBy('short_name');

        $this->assertSame(['A', 'B', 'C'], $problems->keys()->all());

        // Three submissions to A from site A: a WA, an AC and a queued one.
        $this->assertSame(3, $problems['A']['submissions']);
        $this->assertSame(1, $problems['A']['accepted']);
        $this->assertSame(1, $problems['A']['solved_teams']);
        $this->assertSame(20, $problems['A']['first_solve_minute']);

        $this->assertSame(1, $problems['B']['submissions']);
        $this->assertSame(40, $problems['B']['first_solve_minute']);

        // The point of the whole row: a problem nobody solved says so with a
        // null, not by being absent from the chart.
        $this->assertSame(0, $problems['C']['submissions']);
        $this->assertSame(0, $problems['C']['solved_teams']);
        $this->assertNull($problems['C']['first_solve_minute']);
    }

    public function test_the_verdict_chart_accounts_for_every_submission_including_the_queue(): void
    {
        $verdicts = collect($this->actingAs($this->account('site', $this->siteA))
            ->getJson('/api/frontend/reports/site')->assertOk()->json('data.verdicts'))
            ->keyBy('short_name');

        $this->assertSame(2, $verdicts['AC']['total']);
        $this->assertSame(1, $verdicts['WA']['total']);
        // Otherwise the slices add up to less than the submissions counter
        // printed right above them, which reads as a bug.
        $this->assertSame(1, $verdicts['FILA']['total']);
        $this->assertSame(4, $verdicts->sum('total'));
    }

    public function test_the_timeline_covers_the_whole_contest_in_fifteen_minute_buckets(): void
    {
        $timeline = $this->actingAs($this->account('site', $this->siteA))
            ->getJson('/api/frontend/reports/site')->assertOk()->json('data.timeline');

        // 300 minutes of contest, 15 minutes to a bucket.
        $this->assertCount(20, $timeline);
        $this->assertSame([0, 15, 30, 45], array_column(array_slice($timeline, 0, 4), 'minute'));

        // minute 10 (WA), minute 20 (AC), minute 40 (AC), minute 50 (queued).
        $this->assertSame([1, 0], [$timeline[0]['submissions'], $timeline[0]['accepted']]);
        $this->assertSame([1, 1], [$timeline[1]['submissions'], $timeline[1]['accepted']]);
        $this->assertSame([1, 1], [$timeline[2]['submissions'], $timeline[2]['accepted']]);
        $this->assertSame([1, 0], [$timeline[3]['submissions'], $timeline[3]['accepted']]);
        // A flat stretch, not a gap: a missing bucket reads as a shorter
        // contest.
        $this->assertSame(0, $timeline[4]['submissions']);
    }

    public function test_the_judging_delay_histogram_buckets_by_how_long_a_run_waited(): void
    {
        $delay = collect($this->actingAs($this->account('site', $this->siteA))
            ->getJson('/api/frontend/reports/site')->assertOk()->json('data.judging_delay'))
            ->pluck('total', 'label');

        // 30 s and 60 s land in the first bucket, 120 s in the second, and
        // the queued run is not a delay at all.
        $this->assertSame(2, $delay['Ate 1 min']);
        $this->assertSame(1, $delay['1 a 5 min']);
        $this->assertSame(0, $delay['5 a 15 min']);
        $this->assertSame(3, $delay->sum());
    }

    public function test_the_solved_distribution_is_cut_to_the_site_and_comes_from_the_standings(): void
    {
        // This is the aggregation reused from IcpcReportBuilder. Site A's two
        // teams each solved exactly one problem; site B's Equipe Gama also
        // solved one and must not be counted here.
        $distribution = collect($this->actingAs($this->account('site', $this->siteA))
            ->getJson('/api/frontend/reports/site')->assertOk()->json('data.solved_distribution'))
            ->pluck('teams', 'solved');

        $this->assertSame(0, $distribution[0]);
        $this->assertSame(2, $distribution[1]);
    }

    public function test_the_standings_rows_carry_the_site_the_report_cuts_by(): void
    {
        $rows = app(IcpcReportBuilder::class)->rows($this->contest->fresh());

        $bySite = collect($rows)->groupBy('site_id')->map->count();
        $this->assertSame(2, $bySite[$this->siteA->id]);
        $this->assertSame(1, $bySite[$this->siteB->id]);

        // The CSV's column layout is what a filer's tooling parses, and it
        // must not have moved.
        $line = explode("\n", app(IcpcReportBuilder::class)->csv($this->contest->fresh()))[0];
        $this->assertCount(5, explode(',', $line));
    }

    // --- the judge history ------------------------------------------------

    public function test_the_judge_history_separates_a_persons_verdicts_from_the_machines(): void
    {
        $data = $this->actingAs($this->createAdminUser())
            ->getJson('/api/frontend/reports/judge-history')->assertOk()->json('data');

        // Four judged runs in total; three of them by $judge, one by the
        // auto-judge.
        $this->assertSame(4, $data['summary']['judged']);
        $this->assertSame(3, $data['summary']['manual']);
        $this->assertSame(1, $data['summary']['automatic']);
        $this->assertSame(1, $data['summary']['judges']);

        // The listing is manual judgments only: an auto-judged run has no
        // judge, and filing it under a person would make that person's total
        // lie.
        $this->assertSame(3, $data['meta']['total']);
        $this->assertSame([false, false, false], array_map(
            fn (array $item) => $item['judge'] === 'Julgamento automatico',
            $data['items']
        ));
    }

    public function test_each_judges_row_carries_the_counts_and_the_median_wait(): void
    {
        $judges = $this->actingAs($this->createAdminUser())
            ->getJson('/api/frontend/reports/judge-history')->assertOk()->json('data.judges');

        $this->assertCount(1, $judges);
        $this->assertSame($this->judge->user_id, $judges[0]['judge_id']);
        $this->assertSame(3, $judges[0]['judged']);
        $this->assertSame(2, $judges[0]['accepted']);
        $this->assertSame(1, $judges[0]['rejected']);
        // Waits of 30, 120 and 15 seconds.
        $this->assertSame(30, $judges[0]['median_delay_seconds']);
    }

    public function test_a_history_row_says_who_judged_what_for_whom_and_how_long_it_took(): void
    {
        $items = $this->actingAs($this->createAdminUser())
            ->getJson('/api/frontend/reports/judge-history?judge_id='.$this->judge->user_id)
            ->assertOk()->json('data.items');

        // Newest first: a dispute is about something that just happened.
        $newest = $items[0];
        $this->assertSame('Equipe Alfa', $newest['team']);
        $this->assertSame('A - Somatorio', $newest['problem']);
        $this->assertSame('AC', $newest['verdict_short']);
        $this->assertTrue($newest['is_accepted']);
        $this->assertSame($this->judge->fullname, $newest['judge']);
        $this->assertSame(20, $newest['submitted_minute']);
        $this->assertSame(120, $newest['delay_seconds']);
        // A relative, same-origin path -- the client refuses anything else.
        $this->assertSame('/submission/', substr($newest['detail_url'], 0, 12));
    }

    public function test_a_rejudged_run_reports_no_wait_rather_than_a_negative_one(): void
    {
        // Api\RunController::rejudge() clears judged_time. Issue #76 is the
        // reminder that a sign error in exactly this subtraction already
        // shipped once, and a bogus interval must not be averaged into a
        // number an organiser reads as fact.
        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->siteA->id,
            'user_id' => $this->alfa->user_id,
            'problem_id' => $this->problemB->id,
            'language_id' => $this->language->id,
            'status' => 'judged',
            'answer_id' => $this->wrong->id,
            'contest_time' => 600,
            'judged_time' => 300,
            'judge_id' => $this->judge->user_id,
        ]);

        $item = collect($this->actingAs($this->createAdminUser())
            ->getJson('/api/frontend/reports/judge-history')->assertOk()->json('data.items'))
            ->firstWhere('run_id', $run->id);

        $this->assertNull($item['delay_seconds']);
    }

    public function test_the_reports_are_never_cached_by_a_shared_cache(): void
    {
        // Unfrozen and scoped to one person's authorization.
        $this->actingAs($this->createAdminUser())
            ->getJson('/api/frontend/reports/site')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_shells_render_for_their_audience(): void
    {
        $this->actingAs($this->account('site', $this->siteA))->get('/staff/report')
            ->assertOk()->assertSee('data-feature-page="site-report"', false);

        $this->actingAs($this->judge)->get('/judge/history')
            ->assertOk()->assertSee('data-feature-page="judge-history"', false);
    }
}
