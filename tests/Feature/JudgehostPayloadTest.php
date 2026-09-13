<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Judgehost;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Score;
use App\Models\Site;
use App\Models\TestCase as ProblemTestCase;
use App\Services\JudgeWorkQueue;
use Helium\User;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Issue #53 -- the bytes a judgehost may read, and the verdict it may write.
 *
 * The theme here is the one DOMjudge gets wrong: its get_files endpoints
 * filter on the submission or testcase id alone, so any judgehost
 * credential reads every contestant's source and every hidden test case.
 * Most of these tests exist to prove that a judgehost here can read only
 * what it was actually given, and only while it still holds it.
 */
class JudgehostPayloadTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    private Problem $problem;

    private Language $language;

    private Answer $accepted;

    private Answer $wrong;

    /** @var list<string> package directories to remove after the test */
    private array $packageDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()->subMinutes(5)]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problem = Problem::factory()->create(['contest_id' => $this->contest->id, 'auto_judge' => true]);
        $this->language = Language::factory()->create(['contest_id' => $this->contest->id]);

        $this->accepted = Answer::factory()->create([
            'contest_id' => $this->contest->id,
            'short_name' => 'YES',
            'is_accepted' => true,
        ]);
        $this->wrong = Answer::factory()->create([
            'contest_id' => $this->contest->id,
            'short_name' => 'NO',
            'is_accepted' => false,
        ]);
    }

    protected function tearDown(): void
    {
        // Storage::fake() does not cover these: the package path is a real
        // storage_path(), which is how Problem::getPackagePath() resolves it.
        foreach ($this->packageDirs as $dir) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
            @rmdir(dirname($dir));
            @rmdir(dirname($dir, 2));
        }

        parent::tearDown();
    }

    private function as(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function submission(?Problem $problem = null): Run
    {
        $problem ??= $this->problem;

        $team = User::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_type' => 'team',
        ]);

        Storage::disk('local')->put("runs/{$team->user_id}.src", "int main(){}\n");

        return Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $team->user_id,
            'problem_id' => $problem->id,
            'language_id' => $this->language->id,
            'status' => 'pending',
            'answer_id' => null,
            'filename' => 'main.c',
            'source_file' => "runs/{$team->user_id}.src",
            'source_hash' => hash('sha256', "int main(){}\n"),
        ]);
    }

    /**
     * A run already claimed by the given host, without going through
     * fetch-work -- these tests are about what happens after the claim.
     */
    private function heldBy(Judgehost $judgehost, ?Problem $problem = null): Run
    {
        $run = $this->submission($problem);
        $run->update(['status' => 'judging', 'judgehost_id' => $judgehost->id, 'claimed_at' => now()]);

        return $run->fresh();
    }

    private function problemCase(Problem $problem, int $number, string $in, string $out): ProblemTestCase
    {
        Storage::disk('local')->put("tests/{$problem->id}/{$number}.in", $in);
        Storage::disk('local')->put("tests/{$problem->id}/{$number}.out", $out);

        return ProblemTestCase::create([
            'problem_id' => $problem->id,
            'number' => $number,
            'input_file' => "tests/{$problem->id}/{$number}.in",
            'output_file' => "tests/{$problem->id}/{$number}.out",
            'input_hash' => hash('sha256', $in),
            'output_hash' => hash('sha256', $out),
            'is_sample' => false,
        ]);
    }

    // --- the source -------------------------------------------------------

    public function test_the_holder_gets_the_source_it_was_asked_to_judge(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');
        $run = $this->heldBy($host);

        $response = $this->get("/api/judgehost/runs/{$run->id}/source", $this->as($token));

        $response->assertOk();
        $this->assertSame("int main(){}\n", $response->streamedContent());
        // The agent verifies what it got against what fetch-work promised.
        $this->assertSame($run->source_hash, $response->headers->get('X-Source-Sha256'));
    }

    public function test_another_host_cannot_read_that_source(): void
    {
        [$holder] = Judgehost::issue('judge-01');
        [, $otherToken] = Judgehost::issue('judge-02');
        $run = $this->heldBy($holder);

        $this->get("/api/judgehost/runs/{$run->id}/source", $this->as($otherToken))->assertForbidden();
    }

    public function test_a_host_cannot_read_the_source_of_an_unclaimed_run(): void
    {
        [, $token] = Judgehost::issue('judge-01');
        $run = $this->submission();

        $this->get("/api/judgehost/runs/{$run->id}/source", $this->as($token))->assertForbidden();
    }

    public function test_giving_a_run_back_also_gives_up_its_source(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');
        $run = $this->heldBy($host);

        $this->get("/api/judgehost/runs/{$run->id}/source", $this->as($token))->assertOk();

        $this->postJson("/api/judgehost/runs/{$run->id}/give-back", [], $this->as($token))->assertOk();

        // Access follows the lease, not the history of having held it.
        $this->get("/api/judgehost/runs/{$run->id}/source", $this->as($token))->assertForbidden();
    }

    public function test_the_source_needs_a_credential_at_all(): void
    {
        [$host] = Judgehost::issue('judge-01');
        $run = $this->heldBy($host);

        $this->get("/api/judgehost/runs/{$run->id}/source")->assertUnauthorized();
    }

    // --- test cases -------------------------------------------------------

    public function test_the_index_lists_digests_and_sizes_so_an_agent_can_cache(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');
        $run = $this->heldBy($host);
        $case = $this->problemCase($this->problem, 1, "3 4\n", "7\n");

        $response = $this->getJson("/api/judgehost/runs/{$run->id}/testcases", $this->as($token));

        $response->assertOk()
            ->assertJsonPath('data.0.id', $case->id)
            ->assertJsonPath('data.0.number', 1)
            ->assertJsonPath('data.0.input.sha256', hash('sha256', "3 4\n"))
            ->assertJsonPath('data.0.input.bytes', 4)
            ->assertJsonPath('data.0.output.sha256', hash('sha256', "7\n"))
            ->assertJsonPath('data.0.output.bytes', 2);
    }

    public function test_the_holder_reads_the_input_and_the_expected_output(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');
        $run = $this->heldBy($host);
        $case = $this->problemCase($this->problem, 1, "3 4\n", "7\n");

        $in = $this->get("/api/judgehost/runs/{$run->id}/testcases/{$case->id}/input", $this->as($token));
        $out = $this->get("/api/judgehost/runs/{$run->id}/testcases/{$case->id}/output", $this->as($token));

        $in->assertOk();
        $out->assertOk();
        $this->assertSame("3 4\n", $in->streamedContent());
        $this->assertSame("7\n", $out->streamedContent());
    }

    /**
     * The one that matters. Holding any run at all must not be a key to
     * every problem's hidden test data.
     */
    public function test_holding_one_run_does_not_unlock_another_problems_test_data(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');

        $otherProblem = Problem::factory()->create(['contest_id' => $this->contest->id, 'auto_judge' => true]);
        $secret = $this->problemCase($otherProblem, 1, "SECRET INPUT\n", "SECRET OUTPUT\n");

        $run = $this->heldBy($host);

        $this->get("/api/judgehost/runs/{$run->id}/testcases/{$secret->id}/input", $this->as($token))
            ->assertForbidden();
        $this->get("/api/judgehost/runs/{$run->id}/testcases/{$secret->id}/output", $this->as($token))
            ->assertForbidden();
    }

    public function test_another_host_cannot_read_the_test_data(): void
    {
        [$holder] = Judgehost::issue('judge-01');
        [, $otherToken] = Judgehost::issue('judge-02');
        $run = $this->heldBy($holder);
        $case = $this->problemCase($this->problem, 1, "3 4\n", "7\n");

        $this->get("/api/judgehost/runs/{$run->id}/testcases/{$case->id}/input", $this->as($otherToken))
            ->assertForbidden();
        $this->getJson("/api/judgehost/runs/{$run->id}/testcases", $this->as($otherToken))
            ->assertForbidden();
    }

    // --- the problem package (#120) ---------------------------------------

    /**
     * A problem's package directory, with whichever custom scripts the test
     * asks for. This is the real layout Problem::getPackagePath() resolves,
     * not a stand-in: storage/app/problems/{contest}/{basename}/{hook}/{ext}.
     */
    private function packageScripts(Problem $problem, array $scripts): void
    {
        foreach ($scripts as $hook => $body) {
            $dir = storage_path("app/problems/{$problem->contest_id}/{$problem->basename}/{$hook}");
            @mkdir($dir, 0755, true);
            file_put_contents($dir.'/'.$this->language->extension, $body);
            $this->packageDirs[] = $dir;
        }
    }

    public function test_the_payload_declares_which_custom_scripts_the_problem_uses(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');
        $this->packageScripts($this->problem, ['compare' => "#!/bin/sh\nexit 0\n"]);
        $run = $this->heldBy($host);

        $manifest = app(JudgeWorkQueue::class)
            ->workPayload($run->fresh())['problem']['package'];

        // Stated, not guessed: an agent has to know a compare script exists
        // before it can decide it is safe to judge.
        $this->assertSame(hash('sha256', "#!/bin/sh\nexit 0\n"), $manifest['compare']['sha256']);
        $this->assertNull($manifest['compile']);
        $this->assertNull($manifest['run']);

        $this->get("/api/judgehost/runs/{$run->id}/package/compare", $this->as($token))
            ->assertOk();
    }

    public function test_a_problem_with_no_custom_scripts_declares_none(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');
        $run = $this->heldBy($host);

        $manifest = app(JudgeWorkQueue::class)
            ->workPayload($run->fresh())['problem']['package'];

        $this->assertSame(['compile' => null, 'run' => null, 'compare' => null], $manifest);

        $this->get("/api/judgehost/runs/{$run->id}/package/compare", $this->as($token))
            ->assertNotFound();
    }

    public function test_the_holder_can_fetch_each_kind_of_script(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');
        $this->packageScripts($this->problem, [
            'compile' => "compile me\n",
            'run' => "run me\n",
            'compare' => "compare me\n",
        ]);
        $run = $this->heldBy($host);

        foreach (['compile', 'run', 'compare'] as $kind) {
            $response = $this->get("/api/judgehost/runs/{$run->id}/package/{$kind}", $this->as($token));
            $response->assertOk();
            $this->assertSame("{$kind} me\n", $response->streamedContent());
            $this->assertSame(hash('sha256', "{$kind} me\n"), $response->headers->get('X-Script-Sha256'));
        }
    }

    public function test_another_host_cannot_read_the_package(): void
    {
        [$holder] = Judgehost::issue('judge-01');
        [, $otherToken] = Judgehost::issue('judge-02');
        $this->packageScripts($this->problem, ['compare' => "secret checker\n"]);
        $run = $this->heldBy($holder);

        $this->get("/api/judgehost/runs/{$run->id}/package/compare", $this->as($otherToken))
            ->assertForbidden();
    }

    public function test_giving_the_run_back_gives_up_the_package_too(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');
        $this->packageScripts($this->problem, ['compare' => "secret checker\n"]);
        $run = $this->heldBy($host);

        $this->get("/api/judgehost/runs/{$run->id}/package/compare", $this->as($token))->assertOk();
        $this->postJson("/api/judgehost/runs/{$run->id}/give-back", [], $this->as($token))->assertOk();

        $this->get("/api/judgehost/runs/{$run->id}/package/compare", $this->as($token))->assertForbidden();
    }

    /**
     * A checker is a hidden artefact of the problem, exactly like the test
     * data: holding one run must not be a key to another problem's.
     */
    public function test_the_package_is_scoped_to_the_held_runs_problem(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');

        $otherProblem = Problem::factory()->create(['contest_id' => $this->contest->id, 'auto_judge' => true]);
        $this->packageScripts($otherProblem, ['compare' => "other problems checker\n"]);

        $run = $this->heldBy($host);

        // The held run's own problem defines no compare script, so there is
        // nothing to serve -- and certainly not the other problem's.
        $response = $this->get("/api/judgehost/runs/{$run->id}/package/compare", $this->as($token));

        $response->assertNotFound();
        $this->assertStringNotContainsString('other problems checker', (string) $response->getContent());
    }

    public function test_the_package_needs_a_credential(): void
    {
        [$host] = Judgehost::issue('judge-01');
        $this->packageScripts($this->problem, ['compare' => "x\n"]);
        $run = $this->heldBy($host);

        $this->get("/api/judgehost/runs/{$run->id}/package/compare")->assertUnauthorized();
    }

    // --- the verdict ------------------------------------------------------

    public function test_a_reported_verdict_judges_the_run_and_moves_the_scoreboard(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');
        $run = $this->heldBy($host);

        $this->postJson("/api/judgehost/runs/{$run->id}/result", [
            'verdict' => 'YES',
            'message' => 'Accepted',
            'stdout' => '7',
        ], $this->as($token))->assertOk()->assertJsonPath('data.answer_id', $this->accepted->id);

        $run->refresh();
        $this->assertSame('judged', $run->status);
        $this->assertSame($this->accepted->id, $run->answer_id);
        $this->assertSame('Accepted', $run->auto_judge_result);

        // The verdict went through the same path a local judging takes, so
        // the scoreboard was recomputed rather than left behind.
        $this->assertDatabaseHas('scores', [
            'contest_id' => $run->contest_id,
            'user_id' => $run->user_id,
            'problem_id' => $run->problem_id,
        ]);
        $this->assertTrue(
            (bool) Score::where('user_id', $run->user_id)->where('problem_id', $run->problem_id)->value('is_solved')
        );
    }

    public function test_reporting_a_verdict_releases_the_lease(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');
        $run = $this->heldBy($host);

        $this->postJson("/api/judgehost/runs/{$run->id}/result", ['verdict' => 'NO'], $this->as($token))->assertOk();

        $run->refresh();
        // A judged run must never also look leased -- the reaper would
        // otherwise find it and hand a finished run back to the queue.
        $this->assertNull($run->judgehost_id);
        $this->assertNull($run->claimed_at);
    }

    public function test_a_host_cannot_report_on_a_run_it_does_not_hold(): void
    {
        [$holder] = Judgehost::issue('judge-01');
        [, $otherToken] = Judgehost::issue('judge-02');
        $run = $this->heldBy($holder);

        $this->postJson("/api/judgehost/runs/{$run->id}/result", ['verdict' => 'YES'], $this->as($otherToken))
            ->assertForbidden();

        $this->assertSame('judging', $run->fresh()->status);
    }

    /**
     * The race the lease creates: a host stalls, its lease expires, the run
     * goes to a second machine, and then the first one wakes up and
     * answers. Its answer is about work that is no longer its own.
     */
    public function test_a_late_answer_after_the_lease_expired_is_refused(): void
    {
        [$slow, $slowToken] = Judgehost::issue('judge-01');
        [$fast] = Judgehost::issue('judge-02');

        $run = $this->heldBy($slow);

        // The reaper returns it and the second machine takes it.
        $run->update(['claimed_at' => now()->subMinutes(30)]);
        app(JudgeWorkQueue::class)->expireStaleLeases();
        $run->refresh()->update(['status' => 'judging', 'judgehost_id' => $fast->id, 'claimed_at' => now()]);

        $this->postJson("/api/judgehost/runs/{$run->id}/result", ['verdict' => 'YES'], $this->as($slowToken))
            ->assertForbidden();

        $this->assertSame('judging', $run->fresh()->status);
        $this->assertSame($fast->id, $run->fresh()->judgehost_id);
    }

    public function test_the_same_run_cannot_be_answered_twice(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');
        $run = $this->heldBy($host);

        $this->postJson("/api/judgehost/runs/{$run->id}/result", ['verdict' => 'YES'], $this->as($token))->assertOk();

        // The lease is gone with the first answer, so the second is refused
        // before it can overwrite the verdict.
        $this->postJson("/api/judgehost/runs/{$run->id}/result", ['verdict' => 'NO'], $this->as($token))
            ->assertForbidden();

        $this->assertSame($this->accepted->id, $run->fresh()->answer_id);
    }

    /**
     * The lease says "this is yours"; the status says "it is still open".
     * They come apart when a jury member judges the run by hand while a
     * host is working on it -- the host still holds a valid lease, and its
     * answer would overwrite a human's. Checked inside the same
     * transaction as the write, under a row lock, because between reading
     * the status and writing the verdict is exactly where two judgehosts
     * would otherwise interleave.
     */
    public function test_a_verdict_for_a_run_that_is_no_longer_open_is_refused(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');
        $run = $this->heldBy($host);

        // Judged by someone else; the lease was never cleared.
        $run->update(['status' => 'judged', 'answer_id' => $this->wrong->id]);

        $this->postJson("/api/judgehost/runs/{$run->id}/result", ['verdict' => 'YES'], $this->as($token))
            ->assertStatus(409);

        $run->refresh();
        $this->assertSame('judged', $run->status);
        $this->assertSame($this->wrong->id, $run->answer_id);
    }

    public function test_a_verdict_this_contest_does_not_define_is_refused(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');
        $run = $this->heldBy($host);

        $this->postJson("/api/judgehost/runs/{$run->id}/result", ['verdict' => 'MAYBE'], $this->as($token))
            ->assertStatus(422)
            ->assertJsonValidationErrors('verdict');

        // Not judged-with-no-answer, which is what a free-form string would
        // have produced: a run the scoreboard silently reads as unsolved.
        $this->assertSame('judging', $run->fresh()->status);
    }

    public function test_a_verdict_belonging_to_a_different_contest_is_refused(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');
        $run = $this->heldBy($host);

        $elsewhere = Contest::factory()->create();
        Answer::factory()->create(['contest_id' => $elsewhere->id, 'short_name' => 'ZZ']);

        $this->postJson("/api/judgehost/runs/{$run->id}/result", ['verdict' => 'ZZ'], $this->as($token))
            ->assertStatus(422)
            ->assertJsonValidationErrors('verdict');
    }

    public function test_an_agent_can_ask_which_verdicts_this_contest_uses(): void
    {
        [, $token] = Judgehost::issue('judge-01');

        $response = $this->getJson("/api/judgehost/contests/{$this->contest->id}/answers", $this->as($token));

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            ['NO', 'YES'],
            array_column($response->json('data'), 'short_name')
        );
    }

    public function test_the_vocabulary_still_needs_a_credential(): void
    {
        $this->getJson("/api/judgehost/contests/{$this->contest->id}/answers")->assertUnauthorized();
    }
}
