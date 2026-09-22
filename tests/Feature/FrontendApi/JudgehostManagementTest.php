<?php

namespace Tests\Feature\FrontendApi;

use App\Models\Contest;
use App\Models\Judgehost;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use Helium\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Issue #53, phase 4 -- the judge pool as an operator sees it.
 *
 * Phases 1 to 3 built a protocol nobody could look at. Today the only way
 * to add a judge machine is a shell on the server and
 * `php artisan judgehost:create`, and the only way to find out whether one
 * is alive is to read the runs table. This is the API that ends that, and
 * the two things it has to get right are the two that cost a contest time:
 * a machine that has died must not look healthy, and disabling a machine
 * must put its work back in the queue immediately rather than leaving it
 * stranded for the length of a lease.
 */
class JudgehostManagementTest extends TestCase
{
    private function admin(): User
    {
        return $this->createTestUser(['user_type' => 'admin']);
    }

    public function test_only_an_admin_reaches_the_judge_pool(): void
    {
        foreach (['team', 'judge'] as $type) {
            $this->actingAs($this->createTestUser(['user_type' => $type]))
                ->getJson('/api/frontend/judgehosts')
                ->assertStatus(403);
        }

        $this->actingAs($this->admin())
            ->getJson('/api/frontend/judgehosts')
            ->assertStatus(200)
            ->assertJsonPath('data.capabilities.can_manage_judges', true);
    }

    /**
     * The five states, each against a machine that really is in it. The
     * order matters and is asserted with it: a disabled machine that is
     * also stale is a machine someone disabled, and saying "stale" would
     * send the operator looking for a network fault that is not there.
     */
    public function test_each_machine_reports_the_state_it_is_actually_in(): void
    {
        Judgehost::issue('nunca-registrou');

        $idle = Judgehost::issue('ocioso')[0];
        $idle->update(['last_seen_at' => now()->subSeconds(5)]);

        $stale = Judgehost::issue('sumiu')[0];
        $stale->update(['last_seen_at' => now()->subMinutes(10)]);

        $disabled = Judgehost::issue('desligado')[0];
        // Disabled AND long gone: the state has to say why an operator
        // cannot reach it, and "someone turned it off" is the why.
        $disabled->update(['enabled' => false, 'last_seen_at' => now()->subMinutes(10)]);

        $busy = Judgehost::issue('julgando')[0];
        $busy->update(['last_seen_at' => now()]);
        $this->runHeldBy($busy);

        $states = collect(
            $this->actingAs($this->admin())->getJson('/api/frontend/judgehosts')->json('data.items')
        )->pluck('state', 'name');

        $this->assertSame('never_seen', $states['nunca-registrou']);
        $this->assertSame('idle', $states['ocioso']);
        $this->assertSame('stale', $states['sumiu']);
        $this->assertSame('disabled', $states['desligado']);
        $this->assertSame('judging', $states['julgando']);
    }

    public function test_a_machine_holding_a_run_says_which_one_and_for_how_long(): void
    {
        $host = Judgehost::issue('julgando')[0];
        $host->update(['last_seen_at' => now()]);
        $run = $this->runHeldBy($host, now()->subSeconds(90));

        $item = collect($this->actingAs($this->admin())->getJson('/api/frontend/judgehosts')->json('data.items'))
            ->firstWhere('name', 'julgando');

        $this->assertSame($run->id, $item['holding'][0]['run_id']);
        $this->assertGreaterThanOrEqual(89, $item['holding'][0]['seconds_held']);
    }

    /**
     * #117: a host that has declared nothing can judge anything, because
     * that is what an agent older than #117 does. Rendering that as "can
     * judge nothing" would have an operator disabling a working machine.
     */
    public function test_a_host_that_declared_no_languages_is_not_reported_as_able_to_judge_none(): void
    {
        Judgehost::issue('sem-declaracao');

        $declaring = Judgehost::issue('declarou')[0];
        $declaring->declareCapabilities(['cpp', 'py']);

        $items = collect($this->actingAs($this->admin())->getJson('/api/frontend/judgehosts')->json('data.items'))
            ->keyBy('name');

        $this->assertSame([], $items['sem-declaracao']['languages']);
        $this->assertFalse($items['sem-declaracao']['declares_languages']);

        $this->assertSame(['cpp', 'py'], $items['declarou']['languages']);
        $this->assertTrue($items['declarou']['declares_languages']);
    }

    /**
     * Issue #303 -- a divergencia entre duas maquinas do parque e VISTA.
     *
     * Este e o motivo de a versao ser guardada. Ela nao roteia nada (ver
     * `Judgehost::canJudge()`): o que ela muda e que o organizador ve, na
     * tela dos judgehosts, que a `judge-01` compila com GCC 15 e a
     * `judge-02` com GCC 13 -- em vez de descobrir isso num rejulgamento
     * que mudou de veredito no meio da maratona.
     *
     * `language_versions` omite a extensao sem versao de proposito: ausente
     * quer dizer "este host nao disse", e nunca "nao tem" -- a linguagem
     * continua listada em `languages`.
     */
    public function test_a_tela_mostra_com_que_versao_cada_maquina_julga(): void
    {
        Judgehost::issue('judge-01')[0]->declareCapabilities(
            ['c_gcc13', 'sh'],
            ['c_gcc13' => '15.2.0']
        );

        Judgehost::issue('judge-02')[0]->declareCapabilities(
            ['c_gcc13', 'sh'],
            ['c_gcc13' => '13.2.1']
        );

        $items = collect($this->actingAs($this->admin())->getJson('/api/frontend/judgehosts')->json('data.items'))
            ->keyBy('name');

        $this->assertSame(['c_gcc13' => '15.2.0'], $items['judge-01']['language_versions']);
        $this->assertSame(['c_gcc13' => '13.2.1'], $items['judge-02']['language_versions']);

        $this->assertSame(
            $items['judge-01']['languages'],
            $items['judge-02']['languages'],
            'as duas maquinas declaram a MESMA lista de extensoes -- e era so isso que a tela '
            .'mostrava antes da #303, com as duas parecendo intercambiaveis'
        );
    }

    /**
     * The whole point of issuing from the interface: the token that comes
     * back has to be a working credential, not a string the API is willing
     * to print. That is the shape of defect #159 was -- an issuance path
     * that issued nothing usable, with tests that never tried it.
     */
    public function test_an_issued_token_really_registers_a_machine(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson('/api/frontend/judgehosts', ['name' => 'sala-3'], $this->idempotently())
            ->assertStatus(201);

        $token = $response->json('data.token');
        $this->assertIsString($token);

        // Only the digest is kept, so nobody can read it back.
        $this->assertDatabaseHas('judgehosts', [
            'name' => 'sala-3',
            'token_hash' => hash('sha256', $token),
        ]);
        $this->assertDatabaseMissing('judgehosts', ['token_hash' => $token]);

        $this->postJson('/api/remote-judges/v1/register', [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(200);
    }

    /**
     * Disabling is the kill switch, and the run it was holding is the part
     * that gets forgotten. Without the give-back the run sits in `judging`
     * with a dead holder until the lease expires -- ten minutes by default,
     * which is a long time to have a submission nobody is judging.
     */
    public function test_disabling_a_machine_puts_its_work_back_in_the_queue(): void
    {
        $host = Judgehost::issue('sala-3')[0];
        $host->update(['last_seen_at' => now()]);
        $run = $this->runHeldBy($host);

        $this->actingAs($this->admin())
            ->patchJson('/api/frontend/judgehosts/'.$host->id, ['enabled' => false], $this->idempotently())
            ->assertStatus(200)
            ->assertJsonPath('data.released_runs', 1)
            ->assertJsonPath('data.judgehost.state', 'disabled');

        $run->refresh();

        $this->assertSame('pending', $run->status);
        $this->assertNull($run->judgehost_id);
        $this->assertNull($run->claimed_at);
        // #123: the claim is over, so the token that authorised it stops
        // being current. A process on that machine still finishing the
        // judging cannot report against it.
        $this->assertNull($run->claim_token);
    }

    public function test_a_disabled_machine_can_no_longer_authenticate(): void
    {
        [$host, $token] = Judgehost::issue('sala-3');

        $this->postJson('/api/remote-judges/v1/register', [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);

        $this->actingAs($this->admin())
            ->patchJson('/api/frontend/judgehosts/'.$host->id, ['enabled' => false], $this->idempotently())
            ->assertStatus(200);

        $this->postJson('/api/remote-judges/v1/register', [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(401);

        // And back on again with the same credential: disabling is a pause,
        // not a revocation, and an operator who disabled the wrong machine
        // must not have to reinstall it.
        $this->actingAs($this->admin())
            ->patchJson('/api/frontend/judgehosts/'.$host->id, ['enabled' => true], $this->idempotently())
            ->assertStatus(200);

        $this->postJson('/api/remote-judges/v1/register', [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);
    }

    public function test_disabling_a_machine_that_is_already_disabled_releases_nothing(): void
    {
        $host = Judgehost::issue('sala-3')[0];
        $host->update(['enabled' => false]);

        // A run still pointing at it, as an expired lease would leave one.
        // Re-pressing the button must not report work it did not do.
        $this->runHeldBy($host);

        $this->actingAs($this->admin())
            ->patchJson('/api/frontend/judgehosts/'.$host->id, ['enabled' => false], $this->idempotently())
            ->assertStatus(200)
            ->assertJsonPath('data.released_runs', 0);
    }

    /**
     * The shared /api/frontend contract: a write without an
     * Idempotency-Key is refused. Asserted rather than assumed because
     * "disable this machine" is exactly the kind of button an operator
     * double-clicks when a contest is going wrong.
     */
    public function test_a_write_without_an_idempotency_key_is_refused(): void
    {
        $host = Judgehost::issue('sala-3')[0];

        $this->actingAs($this->admin())
            ->patchJson('/api/frontend/judgehosts/'.$host->id, ['enabled' => false])
            ->assertStatus(400);

        $this->assertTrue($host->fresh()->enabled);
    }

    public function test_an_unknown_machine_is_a_404_not_a_crash(): void
    {
        $this->actingAs($this->admin())
            ->patchJson('/api/frontend/judgehosts/999999', ['enabled' => false], $this->idempotently())
            ->assertStatus(404);
    }

    /**
     * Every write on /api/frontend/* requires an Idempotency-Key -- the
     * shared contract in docs/specs/README.md, enforced by
     * App\Support\IdempotencyStore. A fresh key per call, because these
     * tests are asserting on what each call did, not on replay.
     *
     * @return array<string, string>
     */
    private function idempotently(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function runHeldBy(Judgehost $host, $claimedAt = null): Run
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id]);

        return Run::factory()->create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'user_id' => $this->createTestUser(['contest_id' => $contest->id])->user_id,
            'status' => 'judging',
            'judgehost_id' => $host->id,
            'claimed_at' => $claimedAt ?? now(),
            'claim_token' => bin2hex(random_bytes(16)),
        ]);
    }
}
