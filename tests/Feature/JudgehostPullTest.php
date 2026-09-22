<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\ContestLog;
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

    private Answer $answer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()->subMinutes(5)]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problem = Problem::factory()->create(['contest_id' => $this->contest->id, 'auto_judge' => true]);
        $this->language = Language::factory()->create(['contest_id' => $this->contest->id]);
        $this->answer = Answer::factory()->create(['contest_id' => $this->contest->id, 'is_accepted' => true]);
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

        $this->postJson('/api/remote-judges/v1/fetch-work')->assertUnauthorized();
        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as('nao-e-um-token'))->assertUnauthorized();
        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))->assertStatus(204);
    }

    public function test_a_disabled_host_is_refused_exactly_like_an_unknown_one(): void
    {
        [$judgehost, $token] = $this->host();
        $judgehost->update(['enabled' => false]);

        // A decommissioned machine still holding its credential learns
        // nothing about why it stopped working.
        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))->assertUnauthorized();
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

        $response = $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))->assertOk();

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
        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($first))->assertOk();
        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($second))->assertStatus(204);

        $this->assertSame(1, Run::where('status', 'judging')->count());
    }

    public function test_nothing_to_do_is_a_204_and_not_an_error(): void
    {
        [, $token] = $this->host();

        // The agent's signal to back off.
        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))->assertStatus(204);
    }

    public function test_a_problem_with_auto_judge_off_is_not_handed_out(): void
    {
        [, $token] = $this->host();
        $this->problem->update(['auto_judge' => false]);
        $this->pendingRun();

        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))->assertStatus(204);
    }

    // --- giving back ------------------------------------------------------

    public function test_registering_returns_the_runs_it_just_put_back(): void
    {
        [, $token] = $this->host();
        $run = $this->pendingRun();

        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))->assertOk();

        // DOMjudge's idiom, and why its judgehosts survive a restart: the
        // agent calls this on boot, on endpoint error, and after any failed
        // fetch, so work it might be holding is released by the only party
        // that knows it was lost.
        $response = $this->postJson('/api/remote-judges/v1/register', [], $this->as($token))->assertOk();

        $this->assertSame(1, $response->json('data.reclaimed'));
        $this->assertSame('pending', $run->fresh()->status);
        $this->assertNull($run->fresh()->judgehost_id);
    }

    public function test_registering_does_not_disturb_another_hosts_work(): void
    {
        [, $mine] = $this->host('judge-01');
        [, $theirs] = $this->host('judge-02');
        $this->pendingRun();

        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($theirs))->assertOk();

        // In DOMjudge the hostname is read from the request body and the
        // password is shared, so one credential can have another host's
        // in-flight work given back. Here identity comes from the token.
        $response = $this->postJson('/api/remote-judges/v1/register', [], $this->as($mine))->assertOk();

        $this->assertSame(0, $response->json('data.reclaimed'));
        $this->assertSame(1, Run::where('status', 'judging')->count());
    }

    public function test_a_finished_run_is_not_given_back(): void
    {
        [$judgehost, $token] = $this->host();
        $run = $this->pendingRun();
        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token));

        $run->update(['status' => 'judged']);

        $this->assertSame(0, $this->postJson('/api/remote-judges/v1/register', [], $this->as($token))->json('data.reclaimed'));
        $this->assertSame('judged', $run->fresh()->status);
    }

    public function test_a_host_can_hand_back_one_run_it_cannot_finish(): void
    {
        [, $token] = $this->host();
        $run = $this->pendingRun();
        $claim = $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))
            ->json('data.claim_token');

        $this->postJson(
            "/api/remote-judges/v1/runs/{$run->id}/give-back",
            [],
            $this->as($token) + ['X-Claim-Token' => $claim],
        )->assertOk();

        $this->assertSame('pending', $run->fresh()->status);
    }

    /**
     * Issue #123 -- the hole that "which machine" alone leaves open.
     *
     * A restart is the thing a daemon does most, and it is where this bites:
     * process A claims a run and hangs, the operator restarts, process B
     * registers (which gives the run back) and claims the SAME run, and
     * then A wakes up. Both present the same credential, because they are
     * the same machine, and before the fencing token the server accepted
     * A's stale verdict -- then rejected B's correct one as a conflict.
     */
    public function test_a_stale_process_of_the_same_host_cannot_report_over_the_live_one(): void
    {
        [, $token] = $this->host();
        $run = $this->pendingRun();

        $stale = $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))
            ->json('data.claim_token');

        // The agent is restarted: it registers, which puts the run back,
        // and picks the same run up again under a new claim.
        $this->postJson('/api/remote-judges/v1/register', [], $this->as($token))->assertOk();
        $live = $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))
            ->json('data.claim_token');

        $this->assertNotSame($stale, $live, 'Each claim must get its own token.');

        $this->postJson(
            "/api/remote-judges/v1/runs/{$run->id}/result",
            ['verdict' => $this->answer->short_name],
            $this->as($token) + ['X-Claim-Token' => $stale],
        )->assertForbidden();

        // Still being judged by the process that actually holds it.
        $this->assertSame('judging', $run->fresh()->status);
        $this->assertNull($run->fresh()->answer_id);

        // And the live process is still able to finish.
        $this->postJson(
            "/api/remote-judges/v1/runs/{$run->id}/result",
            ['verdict' => $this->answer->short_name],
            $this->as($token) + ['X-Claim-Token' => $live],
        )->assertOk();

        $this->assertSame('judged', $run->fresh()->status);
    }

    /**
     * The same token, once its claim is over, is no better than a stranger's.
     */
    public function test_a_token_from_a_finished_claim_stops_working(): void
    {
        [, $token] = $this->host();
        $run = $this->pendingRun();

        $claim = $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))
            ->json('data.claim_token');

        $this->postJson(
            "/api/remote-judges/v1/runs/{$run->id}/give-back",
            [],
            $this->as($token) + ['X-Claim-Token' => $claim],
        )->assertOk();

        $this->postJson(
            "/api/remote-judges/v1/runs/{$run->id}/give-back",
            [],
            $this->as($token) + ['X-Claim-Token' => $claim],
        )->assertForbidden();
    }

    public function test_an_expired_lease_retires_the_claim_token_with_it(): void
    {
        [, $token] = $this->host();
        $run = $this->pendingRun();

        $claim = $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))
            ->json('data.claim_token');

        $run->fresh()->update(['claimed_at' => now()->subMinutes(30)]);
        app(JudgeWorkQueue::class)->expireStaleLeases();

        $this->postJson(
            "/api/remote-judges/v1/runs/{$run->id}/result",
            ['verdict' => $this->answer->short_name],
            $this->as($token) + ['X-Claim-Token' => $claim],
        )->assertForbidden();

        $this->assertSame('pending', $run->fresh()->status);
    }

    public function test_a_host_cannot_speak_about_a_run_it_does_not_hold(): void
    {
        [, $mine] = $this->host('judge-01');
        [, $theirs] = $this->host('judge-02');
        $run = $this->pendingRun();
        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($theirs));

        // DOMjudge's file endpoints filter on the testcase or submission id
        // alone, so one judgehost credential reads every hidden test case
        // and every contestant's source in the system. Not inherited.
        $this->postJson("/api/remote-judges/v1/runs/{$run->id}/give-back", [], $this->as($mine))->assertForbidden();
        $this->assertSame('judging', $run->fresh()->status);
    }

    // --- local workers (#126) ---------------------------------------------

    /**
     * Issue #126 -- two workers on ONE machine must not get the same run.
     *
     * They did. getNextPendingRun() selected the oldest pending run and
     * judge() flipped the status afterwards, so anyone who scaled by
     * starting a second autojudge:start got both of them judging the same
     * submission -- capacity wasted rather than added, and two writers on
     * one verdict.
     *
     * This matters beyond the bug: "just run more local workers" is the
     * cheap alternative the spec wanted measured before any of the
     * distributed machinery was justified, and it was not a working
     * configuration to measure.
     */
    public function test_two_local_workers_never_get_the_same_run(): void
    {
        $this->pendingRun();

        $queue = app(JudgeWorkQueue::class);

        $first = $queue->claimNextLocally();
        $second = $queue->claimNextLocally();

        $this->assertNotNull($first);
        $this->assertNull($second, 'A second local worker was handed a run that is already being judged.');
    }

    public function test_a_local_claim_is_not_a_lease_and_is_not_reaped(): void
    {
        $this->pendingRun();
        $queue = app(JudgeWorkQueue::class);

        $run = $queue->claimNextLocally();
        $run->update(['claimed_at' => now()->subHours(2)]);

        // runs:reconcile-stuck (#45) owns a local worker that died; the
        // judgehost lease reaper must not also be touching it, or a long
        // local judging would be yanked out from under the process doing it.
        $this->assertSame(0, $queue->expireStaleLeases());
        $this->assertSame('judging', $run->fresh()->status);
        $this->assertNull($run->fresh()->judgehost_id);
        $this->assertNull($run->fresh()->claim_token);
    }

    public function test_a_locally_claimed_run_is_not_offered_to_a_judgehost(): void
    {
        [$host] = $this->host();
        $this->pendingRun();

        app(JudgeWorkQueue::class)->claimNextLocally();

        $this->assertNull(
            app(JudgeWorkQueue::class)->claimNext($host),
            'A judgehost was handed a run the local worker is already judging.'
        );
    }

    // --- giving back with a reason (#125) ---------------------------------

    private function claimFor(string $token): array
    {
        $run = $this->pendingRun();
        $claim = $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))
            ->json('data.claim_token');

        return [$run, $this->as($token) + ['X-Claim-Token' => $claim]];
    }

    public function test_a_give_back_records_why_and_warns_the_organisers(): void
    {
        [, $token] = $this->host('judge-ufscar-01');
        [$run, $headers] = $this->claimFor($token);

        $this->postJson("/api/remote-judges/v1/runs/{$run->id}/give-back",
            ['reason' => 'linguagem_ausente', 'detail' => 'kotlin nao instalado'], $headers)
            ->assertOk()
            ->assertJsonPath('data.reason', 'linguagem_ausente')
            ->assertJsonPath('data.attempts', 1)
            ->assertJsonPath('data.needs_attention', false);

        $this->assertSame('linguagem_ausente', $run->fresh()->give_back_reason);

        // The reason has to reach the screen the organisation actually
        // looks at (#88), not only a log file on the partner's machine.
        $this->assertDatabaseHas('contest_logs', [
            'contest_id' => $this->contest->id,
            'type' => 'warning',
        ]);
        $log = ContestLog::where('contest_id', $this->contest->id)->latest('id')->first();
        $this->assertStringContainsString('linguagem_ausente', $log->message);
        $this->assertStringContainsString('judge-ufscar-01', $log->message);
    }

    public function test_a_run_every_host_refuses_stops_being_offered_to_hosts(): void
    {
        [, $token] = $this->host();
        $run = $this->pendingRun();

        // Three hosts try it and all hand it back.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $claim = $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))
                ->json('data.claim_token');

            $this->assertNotNull($claim, "Attempt {$attempt} was not offered the run.");

            $this->postJson("/api/remote-judges/v1/runs/{$run->id}/give-back",
                ['reason' => 'linguagem_ausente'], $this->as($token) + ['X-Claim-Token' => $claim])->assertOk();
        }

        $this->assertSame(3, $run->fresh()->give_back_count);

        // Circulating it forever is the failure this replaces.
        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))->assertNoContent();
    }

    public function test_the_last_refusal_is_logged_as_an_error_not_a_warning(): void
    {
        [, $token] = $this->host();
        $run = $this->pendingRun();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $claim = $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))
                ->json('data.claim_token');
            $response = $this->postJson("/api/remote-judges/v1/runs/{$run->id}/give-back",
                ['reason' => 'pacote_indisponivel'], $this->as($token) + ['X-Claim-Token' => $claim]);
        }

        $response->assertJsonPath('data.needs_attention', true);

        // Escalated, not repeated: the first refusals are ordinary and
        // logging them all the same way buries the one that needs a human.
        $log = ContestLog::where('contest_id', $this->contest->id)->latest('id')->first();
        $this->assertSame('error', $log->type);
        $this->assertStringContainsString('nenhum judgehost', $log->message);
    }

    /**
     * The local worker is the fallback, so it must still see the run: the
     * reasons a REMOTE machine gives back are mostly things the server
     * itself has.
     */
    public function test_a_run_hosts_refused_is_still_offered_to_the_local_worker(): void
    {
        [, $token] = $this->host();
        $run = $this->pendingRun();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $claim = $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))
                ->json('data.claim_token');
            $this->postJson("/api/remote-judges/v1/runs/{$run->id}/give-back",
                ['reason' => 'pacote_indisponivel'], $this->as($token) + ['X-Claim-Token' => $claim])->assertOk();
        }

        $this->assertNotNull(
            app(JudgeWorkQueue::class)->claimNextLocally(),
            'The local worker, which has the problem package, was cut off from a run only remote hosts refused.'
        );
    }

    public function test_an_invented_reason_is_refused(): void
    {
        [, $token] = $this->host();
        [$run, $headers] = $this->claimFor($token);

        // Free text does not aggregate, and aggregating is the point.
        $this->postJson("/api/remote-judges/v1/runs/{$run->id}/give-back",
            ['reason' => 'porque sim'], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    // --- capabilities (#117) ----------------------------------------------

    private function otherLanguageRun(string $extension): Run
    {
        $language = Language::factory()->create([
            'contest_id' => $this->contest->id,
            'extension' => $extension,
            // languages is unique on (contest_id, name) too, and the suite
            // creates one in setUp().
            'name' => 'lang-'.$extension,
        ]);

        $run = $this->pendingRun();
        $run->update(['language_id' => $language->id]);

        return $run->fresh();
    }

    public function test_a_host_is_not_handed_a_language_it_cannot_run(): void
    {
        [$host, $token] = $this->host();

        $this->postJson('/api/remote-judges/v1/register', ['languages' => ['py']], $this->as($token))
            ->assertOk()
            ->assertJsonPath('data.languages', ['py']);

        $this->otherLanguageRun('kt');

        // Before #117 this host took the run, failed, and handed it back --
        // over and over, because nothing recorded that it could not do it.
        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))->assertNoContent();
    }

    public function test_a_host_is_handed_a_language_it_declared(): void
    {
        [, $token] = $this->host();

        $this->postJson('/api/remote-judges/v1/register', ['languages' => ['kt', 'py']], $this->as($token))
            ->assertOk();

        $run = $this->otherLanguageRun('kt');

        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))
            ->assertOk()
            ->assertJsonPath('data.run_id', $run->id);
    }

    /**
     * An agent older than #117 declares nothing, and must not be taken out
     * of service by upgrading the server. #125 already bounds what that
     * costs: it gives the run back with a reason, and a run refused enough
     * times stops being offered at all.
     */
    public function test_a_host_that_declares_nothing_can_still_judge_anything(): void
    {
        [, $token] = $this->host();

        $this->postJson('/api/remote-judges/v1/register', [], $this->as($token))->assertOk();
        $run = $this->otherLanguageRun('kt');

        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))
            ->assertOk()
            ->assertJsonPath('data.run_id', $run->id);
    }

    public function test_registering_again_replaces_what_a_host_can_run(): void
    {
        [$host, $token] = $this->host();

        $this->postJson('/api/remote-judges/v1/register', ['languages' => ['kt']], $this->as($token))->assertOk();
        $this->otherLanguageRun('kt');

        // The runtime was removed from the machine; restarting the agent is
        // all it should take to stop being offered that work.
        $this->postJson('/api/remote-judges/v1/register', ['languages' => ['py']], $this->as($token))
            ->assertOk()
            ->assertJsonPath('data.languages', ['py']);

        $this->assertSame(['py'], $host->fresh()->capabilities->pluck('extension')->all());
        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))->assertNoContent();
    }

    /**
     * Issue #303 -- a versao vai junto da capacidade.
     *
     * A spec do julgamento distribuido ja prometia isto ("DTO de claim
     * inclui ... versao de linguagem") e o codigo declarava so a extensao:
     * dois judgehosts de um parque, um com GCC 13 e outro com GCC 15,
     * declaravam capacidade IDENTICA. Numa maratona a versao do compilador e
     * parte do edital, e um rejulgamento na outra maquina podia dar outra
     * resposta sem que nada acusasse.
     */
    public function test_um_host_declara_a_versao_junto_da_capacidade(): void
    {
        [$host, $token] = $this->host();

        $this->postJson('/api/remote-judges/v1/register', [
            'languages' => ['c_gcc13', 'py3'],
            'language_versions' => ['c_gcc13' => '15.2.0', 'py3' => '3.14'],
        ], $this->as($token))
            ->assertOk()
            ->assertJsonPath('data.language_versions.c_gcc13', '15.2.0');

        $this->assertSame(
            ['c_gcc13' => '15.2.0', 'py3' => '3.14'],
            $host->fresh()->capabilities->pluck('version', 'extension')->all()
        );
    }

    /**
     * E um host que reconstruiu a imagem corrige a versao ao re-registrar.
     *
     * Esta e a linha do tempo inteira da #303 em um teste: a mesma maquina,
     * a mesma linguagem, outra imagem. Antes, nada no sistema mudava.
     */
    public function test_reconstruir_a_imagem_atualiza_a_versao_declarada(): void
    {
        [$host, $token] = $this->host();

        $this->postJson('/api/remote-judges/v1/register', [
            'languages' => ['c_gcc13'],
            'language_versions' => ['c_gcc13' => '13.2.1'],
        ], $this->as($token))->assertOk();

        $this->postJson('/api/remote-judges/v1/register', [
            'languages' => ['c_gcc13'],
            'language_versions' => ['c_gcc13' => '15.2.0'],
        ], $this->as($token))->assertOk();

        $this->assertSame(
            ['c_gcc13' => '15.2.0'],
            $host->fresh()->capabilities->pluck('version', 'extension')->all()
        );
    }

    /**
     * Um agente anterior a #303 nao manda versao nenhuma, e nao pode perder
     * nada por isso.
     *
     * `null` quer dizer "este host nao disse", que e a verdade sobre ele. O
     * que nao pode acontecer e a ausencia da versao mexer na CAPACIDADE --
     * a #354 mostrou o que custa uma lista de capacidades parcial.
     */
    public function test_um_agente_que_nao_manda_versao_declara_as_mesmas_linguagens(): void
    {
        [$host, $token] = $this->host();

        $this->postJson('/api/remote-judges/v1/register', ['languages' => ['kt', 'py']], $this->as($token))
            ->assertOk()
            ->assertJsonPath('data.languages', ['kt', 'py'])
            ->assertJsonPath('data.language_versions', []);

        $this->assertSame(
            ['kt' => null, 'py' => null],
            $host->fresh()->capabilities->pluck('version', 'extension')->all()
        );

        $run = $this->otherLanguageRun('kt');

        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))
            ->assertOk()
            ->assertJsonPath('data.run_id', $run->id);
    }

    /**
     * Issue #303 -- a versao e REGISTRO, e nunca um segundo portao.
     *
     * Rotear por versao exigiria que alguem dissesse qual versao um contest
     * exige, e hoje nada no sistema diz isso. Uma regra dessas falha do pior
     * jeito: um parque inteiro deixando de receber trabalho porque o numero
     * nao bateu, com a fila parecendo simplesmente vazia.
     *
     * As duas metades: versao declarada nao tira trabalho de quem tem a
     * extensao, e versao declarada tambem nao DA trabalho a quem nao tem.
     */
    public function test_a_versao_declarada_nao_decide_quem_julga(): void
    {
        [$host, $token] = $this->host();

        $this->postJson('/api/remote-judges/v1/register', [
            'languages' => ['kt'],
            // Uma versao que ninguem pediu, e de um numero que nao casa com
            // nada: continua sendo so a extensao que manda.
            'language_versions' => ['kt' => '1.0.0-inventada', 'py' => '3.14'],
        ], $this->as($token))->assertOk();

        $this->assertSame(
            ['kt'],
            $host->fresh()->capabilities->pluck('extension')->all(),
            'uma versao no mapa deu capacidade a uma extensao que o host NAO declarou'
        );

        $run = $this->otherLanguageRun('kt');

        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))
            ->assertOk()
            ->assertJsonPath('data.run_id', $run->id);
    }

    public function test_the_local_worker_is_never_capability_filtered(): void
    {
        $this->otherLanguageRun('kt');

        // The server has every toolchain the contest is configured for;
        // capability is about what a REMOTE machine happens to have.
        $this->assertNotNull(app(JudgeWorkQueue::class)->claimNextLocally());
    }

    public function test_a_host_can_ask_which_languages_it_might_be_given(): void
    {
        [, $token] = $this->host();

        $response = $this->getJson('/api/remote-judges/v1/languages', $this->as($token));

        $response->assertOk();
        // The commands are what the agent probes -- extensions alone would
        // make it guess which binary "kt" implies.
        $this->assertNotEmpty($response->json('data.0.extension'));
        $this->assertArrayHasKey('run_command', $response->json('data.0'));
    }

    public function test_the_language_list_needs_a_credential(): void
    {
        $this->getJson('/api/remote-judges/v1/languages')->assertUnauthorized();
    }

    public function test_hardware_is_recorded_but_never_used_to_route(): void
    {
        [$host, $token] = $this->host();

        $this->postJson('/api/remote-judges/v1/register',
            ['languages' => ['kt'], 'cpu_count' => 8, 'memory_mb' => 16384], $this->as($token))->assertOk();

        $host->refresh();
        $this->assertSame(8, $host->cpu_count);
        $this->assertSame(16384, $host->memory_mb);

        // A slower host is still offered the work: routing by speed would
        // invent machinery the field solves by policy, and hide a fairness
        // problem instead of showing it.
        $run = $this->otherLanguageRun('kt');
        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))
            ->assertOk()
            ->assertJsonPath('data.run_id', $run->id);
    }

    // --- the lease --------------------------------------------------------

    // --- heartbeat (#124) -------------------------------------------------

    public function test_a_heartbeat_keeps_a_long_judging_from_being_reaped(): void
    {
        [, $token] = $this->host();
        $run = $this->pendingRun();

        $claim = $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))
            ->json('data.claim_token');

        // Long enough to be reaped, if nothing said otherwise.
        $run->fresh()->update(['claimed_at' => now()->subMinutes(30)]);

        $this->postJson(
            "/api/remote-judges/v1/runs/{$run->id}/heartbeat",
            [],
            $this->as($token) + ['X-Claim-Token' => $claim],
        )->assertOk();

        $this->assertSame(0, app(JudgeWorkQueue::class)->expireStaleLeases());
        $this->assertSame('judging', $run->fresh()->status);

        // And the machine that kept beating can still finish.
        $this->postJson(
            "/api/remote-judges/v1/runs/{$run->id}/result",
            ['verdict' => $this->answer->short_name],
            $this->as($token) + ['X-Claim-Token' => $claim],
        )->assertOk();
    }

    /**
     * Without the heartbeat the same judging is lost -- this is the
     * behaviour #124 exists to change, kept so the pair stays honest.
     */
    public function test_without_a_heartbeat_that_same_judging_is_reaped(): void
    {
        [, $token] = $this->host();
        $run = $this->pendingRun();

        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token));
        $run->fresh()->update(['claimed_at' => now()->subMinutes(30)]);

        $this->assertSame(1, app(JudgeWorkQueue::class)->expireStaleLeases());
        $this->assertSame('pending', $run->fresh()->status);
    }

    public function test_a_heartbeat_from_a_retired_claim_is_refused(): void
    {
        [, $token] = $this->host();
        $run = $this->pendingRun();

        $stale = $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))
            ->json('data.claim_token');

        // Restarted: the run goes back and is claimed again.
        $this->postJson('/api/remote-judges/v1/register', [], $this->as($token))->assertOk();
        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token));

        // A process that lost its claim must not be able to hold the run
        // open for the process that has it.
        $this->postJson(
            "/api/remote-judges/v1/runs/{$run->id}/heartbeat",
            [],
            $this->as($token) + ['X-Claim-Token' => $stale],
        )->assertForbidden();
    }

    public function test_a_heartbeat_cannot_reopen_a_judged_run(): void
    {
        [, $token] = $this->host();
        $run = $this->pendingRun();

        $claim = $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token))
            ->json('data.claim_token');

        $this->postJson(
            "/api/remote-judges/v1/runs/{$run->id}/result",
            ['verdict' => $this->answer->short_name],
            $this->as($token) + ['X-Claim-Token' => $claim],
        )->assertOk();

        $this->postJson(
            "/api/remote-judges/v1/runs/{$run->id}/heartbeat",
            [],
            $this->as($token) + ['X-Claim-Token' => $claim],
        )->assertStatus(403);

        $this->assertSame('judged', $run->fresh()->status);
        $this->assertNull($run->fresh()->claimed_at);
    }

    public function test_another_host_cannot_heartbeat_your_run(): void
    {
        [, $mine] = $this->host('judge-01');
        [, $theirs] = $this->host('judge-02');
        $run = $this->pendingRun();

        $claim = $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($mine))
            ->json('data.claim_token');

        $this->postJson(
            "/api/remote-judges/v1/runs/{$run->id}/heartbeat",
            [],
            $this->as($theirs) + ['X-Claim-Token' => $claim],
        )->assertForbidden();
    }

    public function test_a_run_held_past_the_lease_goes_back_to_the_queue(): void
    {
        [, $dead] = $this->host('judge-morto');
        [, $alive] = $this->host('judge-vivo');
        $run = $this->pendingRun();

        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($dead))->assertOk();
        Run::whereKey($run->id)->update(['claimed_at' => now()->subSeconds(601)]);

        // This is the half a give-back-on-register protocol cannot cover: a
        // machine that dies never comes back to give anything back.
        // DOMjudge#2476 is what the gap looks like at a World Finals.
        $response = $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($alive))->assertOk();

        $this->assertSame($run->id, $response->json('data.run_id'));
    }

    public function test_a_run_inside_its_lease_is_left_alone(): void
    {
        [, $working] = $this->host('judge-01');
        [, $other] = $this->host('judge-02');
        $this->pendingRun();

        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($working))->assertOk();

        // Expiring a live judging would hand the same run to a second
        // machine, which is worse than waiting.
        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($other))->assertStatus(204);
    }

    public function test_the_lease_length_is_configurable(): void
    {
        [, $token] = $this->host();
        $run = $this->pendingRun();
        $this->postJson('/api/remote-judges/v1/fetch-work', [], $this->as($token));
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

    // --- a medicao do judgehost remoto (#273) -----------------------------

    /**
     * Issue #273 -- o judgehost remoto nunca enviava os tempos medidos.
     *
     * `recordVerdict()` ja sabia recebe-los pelo payload, e o comentario
     * dele dizia por que: "Ler so o estado local deixaria todo veredito
     * remoto sem medicao -- e maquina remota e exatamente o caso que esta
     * issue existe para comparar". O que faltava era o fio.
     *
     * Sem isto, `JudgehostCalibration` -- que filtra por
     * `whereNotNull('measured_cpu_ms')` -- fica sem dado justamente para as
     * maquinas que ela existe para comparar.
     */
    public function test_a_remote_verdict_carries_the_measured_times(): void
    {
        [, $token] = $this->host('judge-medidor');
        [$run, $headers] = $this->claimFor($token);

        $this->postJson(
            "/api/remote-judges/v1/runs/{$run->id}/result",
            [
                'verdict' => $this->answer->short_name,
                'measured_wall_ms' => 1234,
                'measured_cpu_ms' => 987,
            ],
            $headers,
        )->assertOk();

        $fresh = $run->fresh();

        $this->assertSame(1234, (int) $fresh->measured_wall_ms);
        $this->assertSame(987, (int) $fresh->measured_cpu_ms);
    }

    /**
     * Compatibilidade: um agente antigo nao envia os campos, e o veredito
     * continua valendo.
     *
     * Perder a metrica e ruim; recusar o julgamento por causa dela seria
     * pior.
     */
    public function test_an_agent_that_sends_no_measurement_still_has_its_verdict_accepted(): void
    {
        [, $token] = $this->host('judge-antigo');
        [$run, $headers] = $this->claimFor($token);

        $this->postJson(
            "/api/remote-judges/v1/runs/{$run->id}/result",
            ['verdict' => $this->answer->short_name],
            $headers,
        )->assertOk();

        $fresh = $run->fresh();

        $this->assertSame('judged', $fresh->status);
        $this->assertNull($fresh->measured_wall_ms);
        $this->assertNull($fresh->measured_cpu_ms);
    }

    /**
     * Tempo negativo nao e medicao.
     */
    public function test_a_negative_measurement_is_refused(): void
    {
        [, $token] = $this->host('judge-negativo');
        [$run, $headers] = $this->claimFor($token);

        $this->postJson(
            "/api/remote-judges/v1/runs/{$run->id}/result",
            [
                'verdict' => $this->answer->short_name,
                'measured_cpu_ms' => -5,
            ],
            $headers,
        )->assertStatus(422);

        $this->assertSame('judging', $run->fresh()->status);
    }
}
