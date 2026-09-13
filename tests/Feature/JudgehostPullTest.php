<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Judgehost;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Services\JudgeWorkQueue;
use Helium\User;
use Tests\TestCase;

/**
 * Issue #53 -- judge machines pulling work.
 *
 * At the DOMjudge rule of one judgehost per 10 to 20 teams, a 1000-team
 * contest wants 50 to 100 of them. Everything here is about what stops
 * being true when there is more than one.
 */
class JudgehostPullTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    private Problem $problem;

    private Language $language;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()->subMinutes(5)]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problem = Problem::factory()->create(['contest_id' => $this->contest->id, 'auto_judge' => true]);
        $this->language = Language::factory()->create(['contest_id' => $this->contest->id]);
        Answer::factory()->create(['contest_id' => $this->contest->id, 'is_accepted' => true]);
    }

    private function host(string $name = 'judge-01'): array
    {
        return Judgehost::issue($name);
    }

    private function pendingRun(): Run
    {
        $team = User::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_type' => 'team',
        ]);

        return Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $team->user_id,
            'problem_id' => $this->problem->id,
            'language_id' => $this->language->id,
            'status' => 'pending',
            'answer_id' => null,
        ]);
    }

    private function as(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    // --- the credential ---------------------------------------------------

    public function test_the_endpoints_refuse_anything_but_a_valid_token(): void
    {
        [, $token] = $this->host();

        $this->postJson('/api/judgehost/fetch-work')->assertUnauthorized();
        $this->postJson('/api/judgehost/fetch-work', [], $this->as('nao-e-um-token'))->assertUnauthorized();
        $this->postJson('/api/judgehost/fetch-work', [], $this->as($token))->assertStatus(204);
    }

    public function test_a_disabled_host_is_refused_exactly_like_an_unknown_one(): void
    {
        [$judgehost, $token] = $this->host();
        $judgehost->update(['enabled' => false]);

        // A decommissioned machine still holding its credential learns
        // nothing about why it stopped working.
        $this->postJson('/api/judgehost/fetch-work', [], $this->as($token))->assertUnauthorized();
    }

    public function test_the_raw_token_is_never_stored_or_returned(): void
    {
        [$judgehost, $token] = $this->host();

        $this->assertDatabaseMissing('judgehosts', ['token_hash' => $token]);
        $this->assertSame(hash('sha256', $token), $judgehost->token_hash);
        $this->assertArrayNotHasKey('token_hash', $judgehost->toArray());
    }

    // --- claiming ---------------------------------------------------------

    public function test_a_host_fetches_work_and_the_run_is_marked_as_its_own(): void
    {
        [$judgehost, $token] = $this->host();
        $run = $this->pendingRun();

        $response = $this->postJson('/api/judgehost/fetch-work', [], $this->as($token))->assertOk();

        $this->assertSame($run->id, $response->json('data.run_id'));
        $this->assertSame($this->language->extension, $response->json('data.language.extension'));

        $run->refresh();
        $this->assertSame('judging', $run->status);
        $this->assertSame($judgehost->id, $run->judgehost_id);
        $this->assertNotNull($run->claimed_at);
    }

    public function test_two_hosts_never_get_the_same_run(): void
    {
        [, $first] = $this->host('judge-01');
        [, $second] = $this->host('judge-02');
        $run = $this->pendingRun();

        // The single-worker loop this replaces selected the oldest pending
        // run and returned it without claiming, so two workers asking at
        // the same moment both judged it. Harmless with one worker; wrong
        // with fifty.
        $this->postJson('/api/judgehost/fetch-work', [], $this->as($first))->assertOk();
        $this->postJson('/api/judgehost/fetch-work', [], $this->as($second))->assertStatus(204);

        $this->assertSame(1, Run::where('status', 'judging')->count());
    }

    public function test_nothing_to_do_is_a_204_and_not_an_error(): void
    {
        [, $token] = $this->host();

        // The agent's signal to back off.
        $this->postJson('/api/judgehost/fetch-work', [], $this->as($token))->assertStatus(204);
    }

    public function test_a_problem_with_auto_judge_off_is_not_handed_out(): void
    {
        [, $token] = $this->host();
        $this->problem->update(['auto_judge' => false]);
        $this->pendingRun();

        $this->postJson('/api/judgehost/fetch-work', [], $this->as($token))->assertStatus(204);
    }

    // --- giving back ------------------------------------------------------

    public function test_registering_returns_the_runs_it_just_put_back(): void
    {
        [, $token] = $this->host();
        $run = $this->pendingRun();

        $this->postJson('/api/judgehost/fetch-work', [], $this->as($token))->assertOk();

        // DOMjudge's idiom, and why its judgehosts survive a restart: the
        // agent calls this on boot, on endpoint error, and after any failed
        // fetch, so work it might be holding is released by the only party
        // that knows it was lost.
        $response = $this->postJson('/api/judgehost/register', [], $this->as($token))->assertOk();

        $this->assertSame(1, $response->json('data.reclaimed'));
        $this->assertSame('pending', $run->fresh()->status);
        $this->assertNull($run->fresh()->judgehost_id);
    }

    public function test_registering_does_not_disturb_another_hosts_work(): void
    {
        [, $mine] = $this->host('judge-01');
        [, $theirs] = $this->host('judge-02');
        $this->pendingRun();

        $this->postJson('/api/judgehost/fetch-work', [], $this->as($theirs))->assertOk();

        // In DOMjudge the hostname is read from the request body and the
        // password is shared, so one credential can have another host's
        // in-flight work given back. Here identity comes from the token.
        $response = $this->postJson('/api/judgehost/register', [], $this->as($mine))->assertOk();

        $this->assertSame(0, $response->json('data.reclaimed'));
        $this->assertSame(1, Run::where('status', 'judging')->count());
    }

    public function test_a_finished_run_is_not_given_back(): void
    {
        [$judgehost, $token] = $this->host();
        $run = $this->pendingRun();
        $this->postJson('/api/judgehost/fetch-work', [], $this->as($token));

        $run->update(['status' => 'judged']);

        $this->assertSame(0, $this->postJson('/api/judgehost/register', [], $this->as($token))->json('data.reclaimed'));
        $this->assertSame('judged', $run->fresh()->status);
    }

    public function test_a_host_can_hand_back_one_run_it_cannot_finish(): void
    {
        [, $token] = $this->host();
        $run = $this->pendingRun();
        $this->postJson('/api/judgehost/fetch-work', [], $this->as($token));

        $this->postJson("/api/judgehost/runs/{$run->id}/give-back", [], $this->as($token))->assertOk();

        $this->assertSame('pending', $run->fresh()->status);
    }

    public function test_a_host_cannot_speak_about_a_run_it_does_not_hold(): void
    {
        [, $mine] = $this->host('judge-01');
        [, $theirs] = $this->host('judge-02');
        $run = $this->pendingRun();
        $this->postJson('/api/judgehost/fetch-work', [], $this->as($theirs));

        // DOMjudge's file endpoints filter on the testcase or submission id
        // alone, so one judgehost credential reads every hidden test case
        // and every contestant's source in the system. Not inherited.
        $this->postJson("/api/judgehost/runs/{$run->id}/give-back", [], $this->as($mine))->assertForbidden();
        $this->assertSame('judging', $run->fresh()->status);
    }

    // --- the lease --------------------------------------------------------

    public function test_a_run_held_past_the_lease_goes_back_to_the_queue(): void
    {
        [, $dead] = $this->host('judge-morto');
        [, $alive] = $this->host('judge-vivo');
        $run = $this->pendingRun();

        $this->postJson('/api/judgehost/fetch-work', [], $this->as($dead))->assertOk();
        Run::whereKey($run->id)->update(['claimed_at' => now()->subSeconds(601)]);

        // This is the half a give-back-on-register protocol cannot cover: a
        // machine that dies never comes back to give anything back.
        // DOMjudge#2476 is what the gap looks like at a World Finals.
        $response = $this->postJson('/api/judgehost/fetch-work', [], $this->as($alive))->assertOk();

        $this->assertSame($run->id, $response->json('data.run_id'));
    }

    public function test_a_run_inside_its_lease_is_left_alone(): void
    {
        [, $working] = $this->host('judge-01');
        [, $other] = $this->host('judge-02');
        $this->pendingRun();

        $this->postJson('/api/judgehost/fetch-work', [], $this->as($working))->assertOk();

        // Expiring a live judging would hand the same run to a second
        // machine, which is worse than waiting.
        $this->postJson('/api/judgehost/fetch-work', [], $this->as($other))->assertStatus(204);
    }

    public function test_the_lease_length_is_configurable(): void
    {
        [, $token] = $this->host();
        $run = $this->pendingRun();
        $this->postJson('/api/judgehost/fetch-work', [], $this->as($token));
        Run::whereKey($run->id)->update(['claimed_at' => now()->subSeconds(30)]);

        $this->assertSame(0, app(JudgeWorkQueue::class)->expireStaleLeases(600));
        $this->assertSame(1, app(JudgeWorkQueue::class)->expireStaleLeases(10));
    }

    // --- the credential command ------------------------------------------

    public function test_the_command_issues_a_credential_and_shows_it_once(): void
    {
        $this->artisan('judgehost:create', ['name' => 'judge-ufscar-01'])
            ->expectsOutputToContain('judge-ufscar-01')
            ->expectsOutputToContain('uma unica vez')
            ->assertSuccessful();

        $this->assertDatabaseHas('judgehosts', ['name' => 'judge-ufscar-01', 'enabled' => true]);
    }

    public function test_the_command_refuses_a_duplicate_name(): void
    {
        $this->host('judge-01');

        $this->artisan('judgehost:create', ['name' => 'judge-01'])->assertFailed();
    }
}
