<?php

namespace Tests\Feature\Practice;

use App\Models\Contest;
use App\Models\ProblemBank;
use App\Models\Run;
use App\Models\Score;
use Helium\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Issue #43 -- the practice library, its detail page and its submit path
 * (docs/specs/43-practice.md). Organised around that spec's "Critérios de
 * aceite".
 */
class PracticeLibraryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The practice submit path is gated on issue #49's isolated executor
        // being healthy, and refuses to enable submissions otherwise. These
        // tests are about #43's own rules, so the executor is made to look
        // healthy here; the gate itself is asserted in
        // test_submission_is_refused_with_503_when_the_isolated_executor_is_unavailable.
        config(['autojudge.use_bwrap' => true, 'autojudge.bwrap_path' => '/bin/sh']);
    }

    private function bank(array $attributes = []): ProblemBank
    {
        return ProblemBank::create(array_merge([
            'code' => 'SOMA'.$this->uniqueSuffix(),
            'name' => 'Soma '.$this->uniqueSuffix(),
            'description' => 'Some dois inteiros.',
            'input_description' => 'Dois inteiros a e b.',
            'output_description' => 'A soma de a e b.',
            'sample_input' => "3 5\n",
            'sample_output' => "8\n",
            'time_limit' => 2,
            'memory_limit' => 128,
            'difficulty' => 'easy',
            'tags' => ['matematica'],
            'is_active' => true,
            'version' => 1,
        ], $attributes));
    }

    private function publish(ProblemBank $bank, bool $published = true): int
    {
        $response = $this->actingAs($this->createAdminUser())->postJson(
            "/api/frontend/bank-governance/{$bank->id}/practice",
            ['published' => $published, 'version' => (string) $bank->fresh()->version],
            ['Idempotency-Key' => (string) Str::uuid()],
        )->assertOk();

        // Publishing is an admin action; the tests that follow it are about
        // what a participant or an anonymous visitor sees, so the admin
        // session must not leak into them.
        Auth::logout();
        $this->app['auth']->forgetGuards();

        return (int) $response->json('data.practice_problem_id');
    }

    private function submit(User $user, int $problemId, array $payload = [], ?string $key = null)
    {
        return $this->actingAs($user)->postJson(
            "/api/frontend/practice/problems/{$problemId}/runs",
            array_merge(['language_id' => $this->firstLanguageId(), 'source' => "print(8)\n"], $payload),
            ['Idempotency-Key' => $key ?? (string) Str::uuid()],
        );
    }

    private function firstLanguageId(): int
    {
        return (int) Contest::query()->practice()->firstOrFail()->languages()->value('id');
    }

    // --- Critério 4: anonymous reads, anonymous cannot submit ------------

    public function test_anonymous_reads_the_published_library_and_is_invited_to_sign_in(): void
    {
        $problemId = $this->publish($this->bank(['name' => 'Soma simples']));

        $list = $this->getJson('/api/frontend/practice/problems')->assertOk();
        $this->assertSame('Soma simples', $list->json('items.0.name'));
        // Anonymous has no personal progress to report.
        $this->assertFalse($list->json('items.0.solved'));

        $detail = $this->getJson("/api/frontend/practice/problems/{$problemId}")->assertOk();
        $this->assertFalse($detail->json('capabilities.can_submit'));
        $this->assertTrue($detail->json('capabilities.requires_login'));
        $this->assertNotNull($detail->json('submit_unavailable_reason'));
    }

    public function test_anonymous_post_is_rejected(): void
    {
        $problemId = $this->publish($this->bank());

        $this->postJson("/api/frontend/practice/problems/{$problemId}/runs", [
            'language_id' => $this->firstLanguageId(),
            'source' => "print(8)\n",
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertUnauthorized();

        $this->assertSame(0, Run::count());
    }

    public function test_history_requires_authentication(): void
    {
        $this->getJson('/api/frontend/practice/history')->assertUnauthorized();
    }

    public function test_library_responses_are_never_shared_cache_material(): void
    {
        $this->publish($this->bank());

        // `solved` and `capabilities` are per-viewer; one participant's
        // answer must never be served to the next from a shared cache.
        $this->getJson('/api/frontend/practice/problems')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    // --- Critério 2: publication controls both listing and access --------

    public function test_a_withdrawn_problem_is_not_reachable_by_id(): void
    {
        $bank = $this->bank();
        $problemId = $this->publish($bank);

        $this->getJson("/api/frontend/practice/problems/{$problemId}")->assertOk();

        $this->publish($bank, false);

        $this->getJson("/api/frontend/practice/problems/{$problemId}")->assertNotFound();
        $this->assertSame(0, $this->getJson('/api/frontend/practice/problems')->json('meta.total'));
    }

    public function test_submitting_after_withdrawal_is_a_conflict_not_a_silent_accept(): void
    {
        Bus::fake();
        $user = $this->createTestUser();
        $bank = $this->bank();
        $problemId = $this->publish($bank);

        $this->publish($bank, false);

        $this->submit($user, $problemId)->assertStatus(409);
        $this->assertSame(0, Run::count());
    }

    public function test_a_run_accepted_before_withdrawal_stays_in_the_history(): void
    {
        Bus::fake();
        $user = $this->createTestUser();
        $bank = $this->bank();
        $problemId = $this->publish($bank);

        $this->submit($user, $problemId)->assertStatus(202);
        $this->publish($bank, false);

        // "Se aceito antes da retirada, julgar e manter no histórico."
        $this->assertSame(1, $this->actingAs($user)
            ->getJson('/api/frontend/practice/history')->assertOk()->json('meta.total'));
    }

    // --- Critério 3: one run per idempotency key, private history --------

    public function test_repeating_the_idempotency_key_creates_exactly_one_run(): void
    {
        Bus::fake();
        $user = $this->createTestUser();
        $problemId = $this->publish($this->bank());
        $key = (string) Str::uuid();

        $first = $this->submit($user, $problemId, [], $key)->assertStatus(202);
        $second = $this->submit($user, $problemId, [], $key)->assertStatus(202);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Run::count());
    }

    public function test_history_shows_only_the_owner_and_ignores_a_user_id_in_the_query(): void
    {
        Bus::fake();
        $mine = $this->createTestUser();
        $theirs = $this->createTestUser();
        $problemId = $this->publish($this->bank());

        $this->submit($mine, $problemId)->assertStatus(202);
        $this->submit($theirs, $problemId, ['source' => "print(9)\n"])->assertStatus(202);

        // Even asked directly for the other account, the answer is the
        // actor's own runs: "não aceitar user_id por query."
        $history = $this->actingAs($mine)
            ->getJson('/api/frontend/practice/history?user_id='.$theirs->user_id)
            ->assertOk();

        $this->assertSame(1, $history->json('meta.total'));
        $this->assertSame('pending', $history->json('items.0.status'));
        $this->assertNull($history->json('items.0.verdict'));
        $this->assertNull($history->json('items.0.detail_url'));
    }

    // --- Critério 1: training does not touch event enrollment -----------

    public function test_training_does_not_change_an_enrolled_account_or_its_event(): void
    {
        Bus::fake();

        $event = Contest::factory()->create(['is_active' => true, 'start_time' => now()->subMinutes(5)]);
        $user = $this->createTestUser(['contest_id' => $event->id]);

        $problemId = $this->publish($this->bank());
        $this->submit($user, $problemId)->assertStatus(202);

        // Enrollment untouched...
        $this->assertSame($event->id, $user->fresh()->contest_id);
        // ...and the run landed in the practice contest, not the event.
        $run = Run::firstOrFail();
        $this->assertNotSame($event->id, $run->contest_id);
        $this->assertTrue(Contest::findOrFail($run->contest_id)->is_practice);
        // The event's clock is untouched: a practice contest has no
        // start_time, so contest_time is 0 rather than an event offset.
        $this->assertSame(0, (int) $run->contest_time);
        $this->assertSame(0, Score::where('contest_id', $event->id)->count());
    }

    // --- Critério 5: empty library and empty search are distinct --------

    public function test_an_empty_library_and_an_empty_search_are_distinguishable(): void
    {
        $this->getJson('/api/frontend/practice/problems')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->publish($this->bank(['name' => 'Soma simples']));

        // Something exists, but nothing matches this term -- the client can
        // tell the two apart because it knows whether it sent a query.
        $this->getJson('/api/frontend/practice/problems?q=inexistente')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/frontend/practice/problems?q=Soma')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_statistics_are_reported_as_absent_rather_than_invented(): void
    {
        $this->publish($this->bank());

        // "stats:null quando não implementado ou suprimido por privacidade/
        // baixa amostragem" -- the minimum-sample policy is still open, so
        // null is the honest answer.
        $this->assertNull($this->getJson('/api/frontend/practice/problems')->json('items.0.stats'));
    }

    public function test_solved_reflects_the_viewers_own_accepted_run(): void
    {
        Bus::fake();
        $user = $this->createTestUser();
        $other = $this->createTestUser();
        $problemId = $this->publish($this->bank());

        $this->submit($user, $problemId)->assertStatus(202);

        $accepted = Contest::query()->practice()->firstOrFail()
            ->answers()->where('short_name', 'AC')->firstOrFail();
        Run::firstOrFail()->update(['answer_id' => $accepted->id, 'status' => 'judged']);

        $this->assertTrue($this->actingAs($user)
            ->getJson('/api/frontend/practice/problems')->json('items.0.solved'));

        // Someone else's AC is not the viewer's progress.
        $this->assertFalse($this->actingAs($other)
            ->getJson('/api/frontend/practice/problems')->json('items.0.solved'));
    }

    // --- Submit validation and the #49 gate -----------------------------

    public function test_submission_is_refused_with_503_when_the_isolated_executor_is_unavailable(): void
    {
        Bus::fake();
        $user = $this->createTestUser();
        $problemId = $this->publish($this->bank());

        config(['autojudge.bwrap_path' => '/no/such/sandbox']);

        // "can_submit=false e 503 em mutação quando indisponível."
        $detail = $this->getJson("/api/frontend/practice/problems/{$problemId}")->assertOk();
        $this->assertFalse($detail->json('capabilities.can_submit'));
        $this->assertNotNull($detail->json('submit_unavailable_reason'));

        $this->submit($user, $problemId)->assertStatus(503);
        $this->assertSame(0, Run::count());
    }

    public function test_the_public_unavailable_reason_never_describes_the_host(): void
    {
        $problemId = $this->publish($this->bank());
        config(['autojudge.bwrap_path' => '/opt/secret-host-path/bwrap']);

        $reason = $this->getJson("/api/frontend/practice/problems/{$problemId}")
            ->json('submit_unavailable_reason');

        // "Health pode informar indisponibilidade sem paths/tokens/detalhes
        // de host."
        $this->assertStringNotContainsString('/opt/secret-host-path', $reason);
        $this->assertStringNotContainsString('bwrap', $reason);
    }

    public function test_a_disabled_account_cannot_submit(): void
    {
        Bus::fake();
        $user = $this->createTestUser(['is_enabled' => false]);
        $problemId = $this->publish($this->bank());

        $this->submit($user, $problemId)->assertForbidden();
        $this->assertSame(0, Run::count());
    }

    public function test_a_language_outside_the_practice_contest_is_rejected(): void
    {
        Bus::fake();
        $user = $this->createTestUser();
        $problemId = $this->publish($this->bank());

        $this->submit($user, $problemId, ['language_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('language_id');
    }

    public function test_empty_and_oversized_source_are_rejected(): void
    {
        Bus::fake();
        $user = $this->createTestUser();
        $problemId = $this->publish($this->bank());

        $this->submit($user, $problemId, ['source' => "   \n"])
            ->assertStatus(422)
            ->assertJsonValidationErrors('source');

        $maxBytes = (int) config('autojudge.max_file_size', 100) * 1024;
        $this->submit($user, $problemId, ['source' => str_repeat('x', $maxBytes + 1)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('source');

        $this->assertSame(0, Run::count());
    }

    public function test_the_submit_endpoint_requires_an_idempotency_key(): void
    {
        Bus::fake();
        $user = $this->createTestUser();
        $problemId = $this->publish($this->bank());

        $this->actingAs($user)->postJson("/api/frontend/practice/problems/{$problemId}/runs", [
            'language_id' => $this->firstLanguageId(),
            'source' => "print(8)\n",
        ])->assertStatus(400);
    }
}
