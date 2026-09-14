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
use App\Services\AutoJudgeService;
use App\Services\Judgehost\JudgehostAgent;
use App\Services\Judgehost\JudgehostClient;
use App\Services\Judgehost\WorkMaterialiser;
use Helium\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Issue #116 -- the agent, driven against the real server.
 *
 * The HTTP client is faked, but the fake does not invent responses: it
 * dispatches each request into this application's own router, so the agent
 * talks to the actual routes, the actual middleware and the actual
 * controllers from #112, #113 and #120, and the verdict lands in the actual
 * database. A fake built from hand-written JSON would pass happily on the
 * day someone changed the payload shape.
 */
class JudgehostAgentTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    private Problem $problem;

    private Language $language;

    private Answer $accepted;

    private Answer $wrong;

    /** @var list<string> */
    private array $scratchDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'autojudge.use_bwrap' => false,
            'autojudge.work_dir' => sys_get_temp_dir().'/agent_test_'.getmypid(),
            'autojudge.rss_time_path' => '/nonexistent/gnu-time',
            'autojudge.cgroup_root' => '/sys/fs/cgroup/nao-delegado',
            'judgehost.agent.server' => 'https://contest.example',
            'judgehost.agent.workspace' => 'agent_test_workspace',
            'judgehost.agent.poll_min_seconds' => 0.05,
            'judgehost.agent.poll_max_seconds' => 0.2,
        ]);

        $this->contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()->subMinutes(5)]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problem = Problem::factory()->create([
            'contest_id' => $this->contest->id,
            'auto_judge' => true,
            'time_limit' => 5,
            'memory_limit' => 256,
            'basename' => 'server-side-package',
        ]);
        $this->language = Language::factory()->create([
            'contest_id' => $this->contest->id,
            'extension' => 'sh',
            'compile_command' => 'true',
            'run_command' => 'bash {source}',
        ]);

        $this->accepted = Answer::factory()->create([
            'contest_id' => $this->contest->id, 'short_name' => 'AC', 'is_accepted' => true,
        ]);
        $this->wrong = Answer::factory()->create([
            'contest_id' => $this->contest->id, 'short_name' => 'WA', 'is_accepted' => false,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->scratchDirs as $dir) {
            $this->removeTree($dir);
        }

        $this->removeTree(storage_path('app/agent_test_workspace'));
        $this->removeTree(storage_path('app/problems/'.$this->contest->id));
        $this->removeTree(sys_get_temp_dir().'/agent_test_'.getmypid());

        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * Every request the agent makes is dispatched into this app's router.
     * Nothing about the payload shape is hand-written here, so a change to
     * the real controllers shows up as a failure in this test rather than
     * as a green build and a broken agent.
     */
    private function fakeServerAsThisApp(): void
    {
        Http::fake(function (ClientRequest $request) {
            $response = $this->dispatch($request);

            return Http::response(
                $this->bodyOf($response),
                $response->getStatusCode(),
                $response->headers->all(),
            );
        });
    }

    /**
     * The judgehost endpoints answer with three different response types --
     * JSON, a streamed download for test data, and a BinaryFileResponse for
     * package scripts -- and each needs a different way to get its bytes.
     */
    private function bodyOf(TestResponse $response): string
    {
        $base = $response->baseResponse;

        if ($base instanceof BinaryFileResponse) {
            return (string) file_get_contents($base->getFile()->getPathname());
        }

        if ($base instanceof StreamedResponse) {
            return $response->streamedContent();
        }

        return (string) $base->getContent();
    }

    private function dispatch(ClientRequest $request): TestResponse
    {
        $uri = '/'.ltrim(parse_url($request->url(), PHP_URL_PATH) ?: '', '/');

        return $this->call(
            $request->method(),
            $uri,
            [],
            [],
            [],
            $this->serverHeaders($request),
            $request->body() ?: null,
        );
    }

    /**
     * @return array<string, string>
     */
    private function serverHeaders(ClientRequest $request): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        foreach ($request->headers() as $name => $values) {
            $headers['HTTP_'.strtoupper(str_replace('-', '_', $name))] = implode(', ', $values);
        }

        return $headers;
    }

    private function agentFor(string $token): JudgehostAgent
    {
        $client = new JudgehostClient('https://contest.example', $token, 30);

        return new JudgehostAgent(
            $client,
            new WorkMaterialiser($client),
            app(AutoJudgeService::class),
        );
    }

    /**
     * A submission sitting on the server, with its source and test data
     * where the SERVER would have them -- the agent has to fetch all of it.
     */
    private function pendingRun(string $solution, string $input, string $expected): Run
    {
        $team = User::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_type' => 'team',
        ]);

        $dir = storage_path('app/agent_test_server');
        @mkdir($dir, 0755, true);
        $this->scratchDirs[] = $dir;

        file_put_contents($dir.'/sol_'.$team->user_id.'.sh', $solution);
        file_put_contents($dir.'/1_'.$team->user_id.'.in', $input);
        file_put_contents($dir.'/1_'.$team->user_id.'.out', $expected);

        ProblemTestCase::create([
            'problem_id' => $this->problem->id,
            'number' => 1,
            'input_file' => 'agent_test_server/1_'.$team->user_id.'.in',
            'output_file' => 'agent_test_server/1_'.$team->user_id.'.out',
            'input_hash' => hash('sha256', $input),
            'output_hash' => hash('sha256', $expected),
            'is_sample' => false,
        ]);

        return Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $team->user_id,
            'problem_id' => $this->problem->id,
            'language_id' => $this->language->id,
            'status' => 'pending',
            'answer_id' => null,
            'filename' => 'sol.sh',
            'source_file' => 'agent_test_server/sol_'.$team->user_id.'.sh',
            'source_hash' => hash('sha256', $solution),
        ]);
    }

    private function serverPackageScript(string $hook, string $body): void
    {
        $dir = storage_path("app/problems/{$this->contest->id}/{$this->problem->basename}/{$hook}");
        @mkdir($dir, 0755, true);
        $this->scratchDirs[] = $dir;

        file_put_contents($dir.'/'.$this->language->extension, $body);
        @chmod($dir.'/'.$this->language->extension, 0755);
    }

    // --- the loop ---------------------------------------------------------

    public function test_the_agent_judges_a_run_end_to_end_and_the_verdict_lands(): void
    {
        [, $token] = Judgehost::issue('judge-01');
        $run = $this->pendingRun("read a b\necho \$((a + b))\n", "3 4\n", "7\n");
        $this->fakeServerAsThisApp();

        $this->assertTrue($this->agentFor($token)->tick());

        $run->refresh();
        $this->assertSame('judged', $run->status);
        $this->assertSame($this->accepted->id, $run->answer_id);

        // Through the server's own recordVerdict(), so the scoreboard moved.
        $this->assertTrue(
            (bool) Score::where('user_id', $run->user_id)->where('problem_id', $run->problem_id)->value('is_solved')
        );

        // And the lease was released with the verdict.
        $this->assertNull($run->judgehost_id);
        $this->assertNull($run->claimed_at);
    }

    public function test_a_wrong_answer_comes_back_as_a_wrong_answer(): void
    {
        [, $token] = Judgehost::issue('judge-01');
        $run = $this->pendingRun("read a b\necho 0\n", "3 4\n", "7\n");
        $this->fakeServerAsThisApp();

        $this->agentFor($token)->tick();

        $run->refresh();
        $this->assertSame('judged', $run->status);
        $this->assertSame($this->wrong->id, $run->answer_id);
    }

    /**
     * Issue #120, from the agent's side: a problem with a tolerance checker
     * must be judged by that checker even on a machine that has never seen
     * the problem package. Without the fetch, this submission is WA.
     */
    public function test_a_custom_checker_is_fetched_and_used(): void
    {
        [, $token] = Judgehost::issue('judge-01');

        $this->serverPackageScript('compare', <<<'SH'
#!/bin/sh
e=$(cat "$2"); a=$(cat "$3")
awk -v e="$e" -v a="$a" 'BEGIN{d=e-a; if(d<0)d=-d; exit (d<0.000001)?0:1}'
SH);

        $run = $this->pendingRun("echo 0.3333333\n", "1 3\n", "0.333333\n");
        $this->fakeServerAsThisApp();

        $this->agentFor($token)->tick();

        $run->refresh();
        $this->assertSame(
            $this->accepted->id,
            $run->answer_id,
            'The agent judged by exact comparison on a problem that defines a tolerance checker (#120).'
        );
    }

    public function test_the_agent_refuses_rather_than_judging_a_run_it_cannot_equip_itself_for(): void
    {
        [, $token] = Judgehost::issue('judge-01');
        $run = $this->pendingRun("echo 0.3333333\n", "1 3\n", "0.333333\n");

        // The payload will declare a compare script, and fetching it fails.
        // Judging anyway would produce WA on a correct submission, so the
        // only acceptable outcome is that the run goes back to the queue.
        $this->serverPackageScript('compare', "#!/bin/sh\nexit 0\n");

        Http::fake(function (ClientRequest $request) {
            if (str_contains($request->url(), '/package/')) {
                return Http::response('nope', 500);
            }

            $response = $this->dispatch($request);

            return Http::response($this->bodyOf($response), $response->getStatusCode(), $response->headers->all());
        });

        $this->agentFor($token)->tick();

        $run->refresh();
        $this->assertSame('pending', $run->status, 'An unjudgeable run must be given back, not judged by default rules.');
        $this->assertNull($run->answer_id);
        $this->assertNull($run->judgehost_id);
    }

    /**
     * Issue #125 -- the agent has to say WHY, not just hand the run back.
     *
     * A judgehost giving a run back is ordinary; a run every judgehost
     * gives back is not, and the organisers can only tell the two apart if
     * the machine names which is happening. The reason has to be the real
     * one too -- a generic "sandbox_falhou" on a missing problem package
     * would send whoever reads it looking in the wrong place.
     */
    public function test_the_agent_reports_why_it_could_not_judge(): void
    {
        [, $token] = Judgehost::issue('judge-01');
        $this->serverPackageScript('compare', "#!/bin/sh\nexit 0\n");
        $run = $this->pendingRun("echo 1\n", "1 1\n", "2\n");

        Http::fake(function (ClientRequest $request) {
            if (str_contains($request->url(), '/package/')) {
                return Http::response('nope', 500);
            }

            $response = $this->dispatch($request);

            return Http::response($this->bodyOf($response), $response->getStatusCode(), $response->headers->all());
        });

        $this->agentFor($token)->tick();

        $run->refresh();
        $this->assertSame('pending', $run->status);
        $this->assertSame('pacote_indisponivel', $run->give_back_reason);
        $this->assertSame(1, $run->give_back_count);
    }

    public function test_a_corrupted_transfer_is_reported_as_corruption(): void
    {
        [, $token] = Judgehost::issue('judge-01');
        $run = $this->pendingRun("read a b\necho \$((a + b))\n", "3 4\n", "7\n");

        Http::fake(function (ClientRequest $request) {
            if (str_contains($request->url(), '/source')) {
                return Http::response('truncated', 200);
            }

            $response = $this->dispatch($request);

            return Http::response($this->bodyOf($response), $response->getStatusCode(), $response->headers->all());
        });

        $this->agentFor($token)->tick();

        // Not the same failure as a missing package, and it must not read
        // as one: this points at the network, that points at the server.
        $this->assertSame('transferencia_corrompida', $run->fresh()->give_back_reason);
    }

    public function test_a_corrupted_transfer_is_refused_instead_of_judged(): void
    {
        [, $token] = Judgehost::issue('judge-01');
        $run = $this->pendingRun("read a b\necho \$((a + b))\n", "3 4\n", "7\n");

        // Judging half a test-case file produces a confident, wrong verdict.
        // The digests the server sends are what tells the two apart.
        Http::fake(function (ClientRequest $request) {
            if (str_contains($request->url(), '/source')) {
                return Http::response('truncated', 200);
            }

            $response = $this->dispatch($request);

            return Http::response($this->bodyOf($response), $response->getStatusCode(), $response->headers->all());
        });

        $this->agentFor($token)->tick();

        $this->assertSame('pending', $run->fresh()->status);
    }

    /**
     * Issue #124 -- the agent has to actually beat while it judges, not
     * merely be able to.
     *
     * PHP has no thread to beat from, so this only works because the
     * judging loop yields between test cases. If anyone removes that hook,
     * the endpoint still exists and still passes its own tests, and the
     * agent silently stops using it -- which is why this test watches the
     * wire rather than the endpoint.
     */
    public function test_the_agent_beats_while_it_judges(): void
    {
        [, $token] = Judgehost::issue('judge-01');
        $this->pendingRun("read a b\necho \$((a + b))\n", "3 4\n", "7\n");

        // Lease of 3s means the beat interval is 1s, and the first beat
        // fires after compilation regardless.
        config(['judgehost.lease_seconds' => 3]);

        $beats = 0;
        Http::fake(function (ClientRequest $request) use (&$beats) {
            if (str_contains($request->url(), '/heartbeat')) {
                $beats++;
            }

            $response = $this->dispatch($request);

            return Http::response($this->bodyOf($response), $response->getStatusCode(), $response->headers->all());
        });

        $this->agentFor($token)->tick();

        $this->assertGreaterThan(0, $beats, 'The agent judged without ever telling the server it was alive.');
    }

    /**
     * A claim lost mid-judging is not a reason to abandon the work -- the
     * judging is already paid for -- but it must not be reported either,
     * and the server refusing it is what makes that safe.
     */
    public function test_a_claim_lost_during_judging_does_not_corrupt_the_verdict(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');
        $run = $this->pendingRun("read a b\necho \$((a + b))\n", "3 4\n", "7\n");

        Http::fake(function (ClientRequest $request) use ($run) {
            // The reaper takes the run back the moment judging starts.
            if (str_contains($request->url(), '/heartbeat')) {
                $run->fresh()->update([
                    'status' => 'pending',
                    'judgehost_id' => null,
                    'claimed_at' => null,
                    'claim_token' => null,
                ]);
            }

            $response = $this->dispatch($request);

            return Http::response($this->bodyOf($response), $response->getStatusCode(), $response->headers->all());
        });

        $this->agentFor($token)->tick();

        // Back in the queue for whoever can finish it, with no verdict
        // written by the machine that lost it.
        $run->refresh();
        $this->assertSame('pending', $run->status);
        $this->assertNull($run->answer_id);
    }

    public function test_nothing_to_do_backs_off_instead_of_hammering_the_server(): void
    {
        [, $token] = Judgehost::issue('judge-01');
        $this->fakeServerAsThisApp();

        $agent = $this->agentFor($token);

        $this->assertFalse($agent->tick());
        $first = $agent->currentBackoff();

        $this->assertFalse($agent->tick());
        $this->assertGreaterThan($first, $agent->currentBackoff());
    }

    public function test_the_backoff_resets_once_there_is_work_again(): void
    {
        [, $token] = Judgehost::issue('judge-01');
        $this->fakeServerAsThisApp();

        $agent = $this->agentFor($token);
        $agent->tick();
        $backedOff = $agent->currentBackoff();

        $this->pendingRun("read a b\necho \$((a + b))\n", "3 4\n", "7\n");
        $agent->tick();

        $this->assertLessThan($backedOff, $agent->currentBackoff());
    }

    public function test_registering_reports_what_the_server_put_back(): void
    {
        [$host, $token] = Judgehost::issue('judge-01');
        $run = $this->pendingRun("read a b\necho \$((a + b))\n", "3 4\n", "7\n");
        $run->update(['status' => 'judging', 'judgehost_id' => $host->id, 'claimed_at' => now()]);

        $this->fakeServerAsThisApp();

        $registration = $this->agentFor($token)->register();

        $this->assertSame(1, $registration['reclaimed']);
        $this->assertSame('pending', $run->fresh()->status);
    }

    public function test_two_agents_never_judge_the_same_run(): void
    {
        [, $first] = Judgehost::issue('judge-01');
        [, $second] = Judgehost::issue('judge-02');
        $this->pendingRun("read a b\necho \$((a + b))\n", "3 4\n", "7\n");
        $this->fakeServerAsThisApp();

        $this->assertTrue($this->agentFor($first)->tick());
        // The queue is empty for the second: the claim in #112 is atomic.
        $this->assertFalse($this->agentFor($second)->tick());

        $this->assertSame(1, Run::where('status', 'judged')->count());
    }

    public function test_the_command_refuses_to_start_unconfigured(): void
    {
        config(['judgehost.agent.server' => '', 'judgehost.agent.token' => '']);

        $this->artisan('judgehost:work', ['--once' => true])
            ->expectsOutputToContain('JUDGEHOST_SERVER')
            ->assertExitCode(1);
    }

    public function test_the_command_judges_one_run_and_stops(): void
    {
        [, $token] = Judgehost::issue('judge-01');
        config(['judgehost.agent.token' => $token]);
        $run = $this->pendingRun("read a b\necho \$((a + b))\n", "3 4\n", "7\n");
        $this->fakeServerAsThisApp();

        $this->artisan('judgehost:work', ['--once' => true])->assertExitCode(0);

        $this->assertSame('judged', $run->fresh()->status);
    }
}
