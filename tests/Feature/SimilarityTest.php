<?php

namespace Tests\Feature;

use App\Jobs\RunSimilarityCheckJob;
use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\SimilarityCheck;
use App\Models\SimilarityPair;
use App\Services\Similarity\SimilarityEngineException;
use App\Services\Similarity\SimilarityEngineInterface;
use App\Services\Similarity\SimilarityEngineResult;
use Helium\User;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Similarity\FakeSimilarityEngine;
use Tests\TestCase;

class SimilarityTest extends TestCase
{
    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('similarity-test');
        parent::tearDown();
    }

    private function makeContestProblemLanguage(string $extension = 'cpp_gpp13'): array
    {
        $contest = Contest::factory()->create();
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create([
            'contest_id' => $contest->id,
            'extension' => $extension,
            'is_active' => true,
        ]);

        return [$contest, $problem, $language];
    }

    private function acceptedRun(Contest $contest, Problem $problem, Language $language, string $sourceContents, ?\DateTimeInterface $createdAt = null): Run
    {
        $user = User::factory()->create(['user_type' => 'team', 'contest_id' => $contest->id]);
        $answer = Answer::factory()->create(['contest_id' => $contest->id, 'is_accepted' => true]);

        $path = 'similarity-test/' . uniqid('src_', true) . '.cpp';
        Storage::disk('local')->put($path, $sourceContents);

        $run = Run::factory()->create([
            'contest_id' => $contest->id,
            'user_id' => $user->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'answer_id' => $answer->id,
            'status' => 'judged',
            'source_file' => $path,
            'filename' => 'main.cpp',
        ]);

        if ($createdAt) {
            $run->forceFill(['created_at' => $createdAt])->save();
        }

        return $run;
    }

    private function bindFakeEngine(): FakeSimilarityEngine
    {
        $fake = new FakeSimilarityEngine();
        $this->app->instance(SimilarityEngineInterface::class, $fake);
        return $fake;
    }

    /**
     * IdempotencyStore::handle() requires this header on every mutating
     * call (400 without it) -- a fresh UUID-ish string per call keeps
     * tests that post more than once from accidentally colliding on the
     * same key.
     */
    private function idempotencyHeader(): array
    {
        return ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()];
    }

    public function test_participant_gets_403_from_every_similarity_endpoint(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $run = $this->acceptedRun($contest, $problem, $language, 'int main(){}');

        $this->actingAs($this->createTestUser());
        $this->getJson('/api/frontend/similarity')->assertForbidden();
        $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'threshold' => 80,
        ], $this->idempotencyHeader())->assertForbidden();
        $this->getJson("/api/frontend/similarity/runs/{$run->id}/source")->assertForbidden();
    }

    public function test_index_lists_authorized_problems_with_supported_languages_and_capabilities(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        // Unsupported language must not show up in the problem's language list.
        Language::factory()->create(['contest_id' => $contest->id, 'name' => 'Brainfuck', 'extension' => 'bf', 'is_active' => true]);

        $this->actingAs($this->createAdminUser());
        $response = $this->getJson('/api/frontend/similarity')->assertOk();

        $response->assertJsonPath('data.capabilities.can_start', true);
        $response->assertJsonPath('data.items', []);
        $response->assertJsonPath('data.meta.total', 0);
        $problems = $response->json('data.problems');
        $this->assertCount(1, $problems);
        $this->assertSame($problem->id, $problems[0]['id']);
        $this->assertSame($contest->name, $problems[0]['contest_name']);
        $this->assertCount(1, $problems[0]['languages']);
        $this->assertSame($language->id, $problems[0]['languages'][0]['id']);
    }

    public function test_store_requires_idempotency_key_header(): void
    {
        [, $problem, $language] = $this->makeContestProblemLanguage();

        $this->actingAs($this->createAdminUser());
        $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'threshold' => 80,
        ])->assertStatus(400);
    }

    public function test_store_rejects_unsupported_language_with_422_on_language_field(): void
    {
        [$contest, $problem] = $this->makeContestProblemLanguage();
        $unsupported = Language::factory()->create(['contest_id' => $contest->id, 'name' => 'Brainfuck', 'extension' => 'bf']);

        $this->actingAs($this->createAdminUser());
        $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $unsupported->id,
            'threshold' => 80,
        ], $this->idempotencyHeader())->assertStatus(422)->assertJsonValidationErrors('language_id');

        $this->assertSame(0, SimilarityCheck::count());
    }

    public function test_store_rejects_inactive_language_even_though_it_is_jplag_supported(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $language->update(['is_active' => false]);
        $this->acceptedRun($contest, $problem, $language, 'a');
        $this->acceptedRun($contest, $problem, $language, 'b');

        $this->actingAs($this->createAdminUser());
        $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'threshold' => 80,
        ], $this->idempotencyHeader())->assertStatus(422)->assertJsonValidationErrors('language_id');

        $this->assertSame(0, SimilarityCheck::count());
    }

    public function test_store_rejects_invalid_threshold(): void
    {
        [, $problem, $language] = $this->makeContestProblemLanguage();

        $this->actingAs($this->createAdminUser());
        $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'threshold' => 101,
        ], $this->idempotencyHeader())->assertStatus(422)->assertJsonValidationErrors('threshold');
    }

    public function test_store_rejects_fewer_than_two_eligible_teams(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $this->acceptedRun($contest, $problem, $language, 'int main(){}');

        $this->actingAs($this->createAdminUser());
        $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'threshold' => 80,
        ], $this->idempotencyHeader())->assertStatus(422)->assertJsonValidationErrors('language_id');

        $this->assertSame(0, SimilarityCheck::count());
    }

    public function test_store_uses_last_ac_per_team_with_id_tiebreak_and_ignores_later_non_ac(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $this->bindFakeEngine();

        $sameTime = now()->subHour();
        $teamAUser = User::factory()->create(['user_type' => 'team', 'contest_id' => $contest->id]);
        $answer = Answer::factory()->create(['contest_id' => $contest->id, 'is_accepted' => true]);
        $wrongAnswer = Answer::factory()->create(['contest_id' => $contest->id, 'is_accepted' => false]);

        Storage::disk('local')->put('similarity-test/a_old.cpp', 'old');
        $oldAc = Run::factory()->create([
            'contest_id' => $contest->id, 'user_id' => $teamAUser->user_id, 'problem_id' => $problem->id,
            'language_id' => $language->id, 'answer_id' => $answer->id, 'status' => 'judged',
            'source_file' => 'similarity-test/a_old.cpp', 'filename' => 'a.cpp',
        ]);
        $oldAc->forceFill(['created_at' => $sameTime])->save();

        // A tie on created_at with a HIGHER id must win (spec: "empate escolhe ID maior").
        Storage::disk('local')->put('similarity-test/a_tiewin.cpp', 'tie-winner');
        $tieWinner = Run::factory()->create([
            'contest_id' => $contest->id, 'user_id' => $teamAUser->user_id, 'problem_id' => $problem->id,
            'language_id' => $language->id, 'answer_id' => $answer->id, 'status' => 'judged',
            'source_file' => 'similarity-test/a_tiewin.cpp', 'filename' => 'a.cpp',
        ]);
        $tieWinner->forceFill(['created_at' => $sameTime])->save();
        $this->assertGreaterThan($oldAc->id, $tieWinner->id);

        // Submitted AFTER the accepted run but not itself accepted -- must
        // never replace the accepted snapshot run.
        Storage::disk('local')->put('similarity-test/a_wa.cpp', 'wrong-answer-after');
        Run::factory()->create([
            'contest_id' => $contest->id, 'user_id' => $teamAUser->user_id, 'problem_id' => $problem->id,
            'language_id' => $language->id, 'answer_id' => $wrongAnswer->id, 'status' => 'judged',
            'source_file' => 'similarity-test/a_wa.cpp', 'filename' => 'a.cpp',
            'created_at' => $sameTime->copy()->addMinutes(5),
        ]);

        $teamB = $this->acceptedRun($contest, $problem, $language, 'team-b-source');

        $this->actingAs($this->createAdminUser());
        $response = $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'threshold' => 80,
        ], $this->idempotencyHeader())->assertStatus(202);

        $checkId = $response->json('data.id');
        $check = SimilarityCheck::findOrFail($checkId);
        $this->assertSame('completed', $check->status);
        $this->assertEqualsCanonicalizing([$tieWinner->id, $teamB->id], $check->snapshot['run_ids']);
    }

    public function test_store_dispatches_a_job_that_completes_with_a_known_pair_normalized_to_percent(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $this->bindFakeEngine();

        $runA = $this->acceptedRun($contest, $problem, $language, 'identical source');
        $runB = $this->acceptedRun($contest, $problem, $language, 'identical source');

        $this->actingAs($this->createAdminUser());
        $response = $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'threshold' => 80,
        ], $this->idempotencyHeader())->assertStatus(202);

        $response->assertJsonPath('data.status', 'queued');
        $checkId = $response->json('data.id');

        $check = SimilarityCheck::findOrFail($checkId);
        $this->assertSame('completed', $check->status);
        $this->assertSame(2, $check->team_count);
        $this->assertNotNull($check->engine_version);

        $pair = SimilarityPair::where('similarity_check_id', $checkId)->firstOrFail();
        $this->assertSame(100.0, (float) $pair->similarity_score);
        $this->assertEqualsCanonicalizing([$runA->id, $runB->id], [$pair->run_id_a, $pair->run_id_b]);

        $index = $this->getJson('/api/frontend/similarity')->assertOk();
        $items = $index->json('data.items');
        $this->assertSame('completed', $items[0]['status']);
        $this->assertCount(1, $items[0]['pairs']);
        $this->assertEquals(100, $items[0]['pairs'][0]['similarity_score']);
        $this->assertStringStartsWith('/api/frontend/similarity/runs/', $items[0]['pairs'][0]['source_a_url']);
        $this->assertEqualsCanonicalizing(
            [$runA->user->fullname, $runB->user->fullname],
            [$items[0]['pairs'][0]['team_a'], $items[0]['pairs'][0]['team_b']]
        );
        // Pairs must come back sorted by similarity_score desc -- assert
        // this with a second, lower-scoring pair below; here with only one
        // pair we just confirm the ordering keys are present and correct.
        $this->assertArrayHasKey('excluded_count', $items[0]);
        $this->assertSame(0, $items[0]['excluded_count']);
    }

    public function test_pairs_are_ordered_by_similarity_score_descending(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $fake = $this->bindFakeEngine();

        $runA = $this->acceptedRun($contest, $problem, $language, 'source-a');
        $runB = $this->acceptedRun($contest, $problem, $language, 'source-b');
        $runC = $this->acceptedRun($contest, $problem, $language, 'source-c');

        // Deterministic scores instead of the default identical-content
        // fake behavior, specifically to prove index() doesn't silently
        // fall back to insertion order (see the eager-load ordering fix in
        // SimilarityController::index()).
        $fake->behavior = function ($submissions) use ($runA, $runB, $runC) {
            return new SimilarityEngineResult([
                ['run_id_a' => $runA->id, 'run_id_b' => $runB->id, 'score' => 0.81],
                ['run_id_a' => $runA->id, 'run_id_b' => $runC->id, 'score' => 0.97],
                ['run_id_a' => $runB->id, 'run_id_b' => $runC->id, 'score' => 0.85],
            ], 'fake-1.0.0');
        };

        $this->actingAs($this->createAdminUser());
        $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'threshold' => 80,
        ], $this->idempotencyHeader())->assertStatus(202);

        $index = $this->getJson('/api/frontend/similarity')->assertOk();
        $scores = collect($index->json('data.items.0.pairs'))->pluck('similarity_score')->all();
        $this->assertEquals([97, 85, 81], $scores);
    }

    /**
     * A report whose returned pair count reaches the configured cap
     * (JPlag's own -n flag) can't be told apart, from stored data alone,
     * from "there were exactly that many". pairs_truncated flags that
     * ambiguity instead of a report that looks complete either way.
     */
    public function test_pairs_truncated_flag_is_set_when_the_returned_count_reaches_the_configured_cap(): void
    {
        config(['similarity.max_pairs_per_report' => 2]);

        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $fake = $this->bindFakeEngine();

        $runA = $this->acceptedRun($contest, $problem, $language, 'a');
        $runB = $this->acceptedRun($contest, $problem, $language, 'b');
        $runC = $this->acceptedRun($contest, $problem, $language, 'c');

        $fake->behavior = fn () => new SimilarityEngineResult([
            ['run_id_a' => $runA->id, 'run_id_b' => $runB->id, 'score' => 0.90],
            ['run_id_a' => $runA->id, 'run_id_b' => $runC->id, 'score' => 0.85],
        ], 'fake-1.0.0');

        $this->actingAs($this->createAdminUser());
        $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'threshold' => 80,
        ], $this->idempotencyHeader())->assertStatus(202);

        $index = $this->getJson('/api/frontend/similarity')->assertOk();
        $this->assertTrue($index->json('data.items.0.pairs_truncated'));
    }

    public function test_pairs_truncated_flag_is_false_when_under_the_cap(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $this->bindFakeEngine();

        $this->acceptedRun($contest, $problem, $language, 'identical');
        $this->acceptedRun($contest, $problem, $language, 'identical');

        $this->actingAs($this->createAdminUser());
        $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'threshold' => 80,
        ], $this->idempotencyHeader())->assertStatus(202);

        $index = $this->getJson('/api/frontend/similarity')->assertOk();
        $this->assertFalse($index->json('data.items.0.pairs_truncated'));
    }

    public function test_zero_pairs_above_threshold_is_a_successful_completed_check(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $this->bindFakeEngine();

        $this->acceptedRun($contest, $problem, $language, 'source one');
        $this->acceptedRun($contest, $problem, $language, 'totally different source');

        $this->actingAs($this->createAdminUser());
        $response = $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'threshold' => 80,
        ], $this->idempotencyHeader())->assertStatus(202);

        $check = SimilarityCheck::findOrFail($response->json('data.id'));
        $this->assertSame('completed', $check->status);
        $this->assertSame(0, SimilarityPair::where('similarity_check_id', $check->id)->count());

        $index = $this->getJson('/api/frontend/similarity')->assertOk();
        $this->assertSame([], $index->json('data.items.0.pairs'));
    }

    public function test_engine_timeout_marks_check_failed_not_stuck_running(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $fake = $this->bindFakeEngine();
        $fake->throws = new SimilarityEngineException('engine_timeout', 'boom');

        $this->acceptedRun($contest, $problem, $language, 'a');
        $this->acceptedRun($contest, $problem, $language, 'b');

        $this->actingAs($this->createAdminUser());
        $response = $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'threshold' => 80,
        ], $this->idempotencyHeader())->assertStatus(202);

        $check = SimilarityCheck::findOrFail($response->json('data.id'));
        $this->assertSame('failed', $check->status);
        $this->assertNotNull($check->safeErrorMessage());

        $index = $this->getJson('/api/frontend/similarity')->assertOk();
        $item = $index->json('data.items.0');
        $this->assertSame('failed', $item['status']);
        $this->assertIsString($item['error_message']);
    }

    /**
     * Distinct from an engine failure: JPlag itself succeeds, but writing
     * its result fails (e.g. a referenced run deleted between the snapshot
     * and this write would trip the similarity_pairs FK in production) --
     * this must get a specific safe error code, not the generic
     * "worker_interrupted" the top-level failed() callback would apply.
     *
     * A real FK violation isn't reliably reproducible here: this app's
     * sqlite test connection (config/database.php) doesn't enable
     * 'foreign_key_constraints', and RefreshDatabase already has a
     * transaction open by the time a test body runs, so a mid-test
     * `PRAGMA foreign_keys = ON` is a silent no-op (SQLite doesn't allow
     * toggling it inside a transaction). Instead this exercises the same
     * try/catch with a deterministic, connection-independent failure: a
     * non-numeric score makes `$pair['score'] * 100` throw a TypeError
     * before any query even runs, which is enough to prove
     * RunSimilarityCheckJob::persistResult()'s own catch block -- not
     * SQLite's constraint enforcement -- is what maps the failure to
     * 'result_persist_failed'.
     */
    public function test_result_persist_failure_gets_a_specific_safe_error_code(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $fake = $this->bindFakeEngine();

        $runA = $this->acceptedRun($contest, $problem, $language, 'a');
        $runB = $this->acceptedRun($contest, $problem, $language, 'b');

        $fake->behavior = fn () => new SimilarityEngineResult([
            ['run_id_a' => $runA->id, 'run_id_b' => $runB->id, 'score' => 'not-a-number'],
        ], 'fake-1.0.0');

        $this->actingAs($this->createAdminUser());
        $response = $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'threshold' => 80,
        ], $this->idempotencyHeader())->assertStatus(202);

        $check = SimilarityCheck::findOrFail($response->json('data.id'));
        $this->assertSame('failed', $check->status);
        $this->assertSame('result_persist_failed', $check->safe_error_code);
    }

    /**
     * A team whose source JPlag itself couldn't parse (distinct from a
     * missing file, which EligibleRunFinder already catches) must still
     * be visible in the report's excluded-teams accounting -- silently
     * dropping it would defeat the point for exactly the kind of
     * submission most worth flagging.
     */
    public function test_unparseable_submission_is_recorded_as_excluded_not_silently_dropped(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $fake = $this->bindFakeEngine();

        $runA = $this->acceptedRun($contest, $problem, $language, 'a');
        $runB = $this->acceptedRun($contest, $problem, $language, 'b');

        $fake->behavior = fn () => new SimilarityEngineResult([], 'fake-1.0.0', [$runB->id]);

        $this->actingAs($this->createAdminUser());
        $response = $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'threshold' => 80,
        ], $this->idempotencyHeader())->assertStatus(202);

        $check = SimilarityCheck::findOrFail($response->json('data.id'));
        $this->assertSame('completed', $check->status);
        $excludedReasons = collect($check->snapshot['excluded'])->pluck('reason')->all();
        $this->assertContains('unparseable_source', $excludedReasons);

        $index = $this->getJson('/api/frontend/similarity')->assertOk();
        $item = $index->json('data.items.0');
        $this->assertSame(1, $item['excluded_count']);
        $this->assertSame(['unparseable_source' => 1], $item['excluded_reasons']);
        $this->assertNotNull($runA);
    }

    public function test_repeating_the_same_idempotency_key_and_payload_does_not_duplicate_the_job(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $this->bindFakeEngine();
        $this->acceptedRun($contest, $problem, $language, 'a');
        $this->acceptedRun($contest, $problem, $language, 'b');

        $this->actingAs($this->createAdminUser());
        $payload = ['problem_id' => $problem->id, 'language_id' => $language->id, 'threshold' => 80];
        $headers = $this->idempotencyHeader();

        $first = $this->postJson('/api/frontend/similarity/checks', $payload, $headers)->assertStatus(202);
        $second = $this->postJson('/api/frontend/similarity/checks', $payload, $headers)->assertStatus(202);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, SimilarityCheck::count());
    }

    public function test_same_idempotency_key_with_different_payload_is_409(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $this->bindFakeEngine();
        $this->acceptedRun($contest, $problem, $language, 'a');
        $this->acceptedRun($contest, $problem, $language, 'b');

        $this->actingAs($this->createAdminUser());
        $headers = $this->idempotencyHeader();

        $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id, 'language_id' => $language->id, 'threshold' => 80,
        ], $headers)->assertStatus(202);

        $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id, 'language_id' => $language->id, 'threshold' => 50,
        ], $headers)->assertStatus(409);

        $this->assertSame(1, SimilarityCheck::count());
    }

    public function test_running_check_for_the_same_snapshot_returns_409_with_reconciliation_id(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $this->acceptedRun($contest, $problem, $language, 'a');
        $this->acceptedRun($contest, $problem, $language, 'b');

        $this->bindFakeEngine();

        $this->actingAs($this->createAdminUser());
        $payload = ['problem_id' => $problem->id, 'language_id' => $language->id, 'threshold' => 80];

        // Force the first check to remain "running" by writing it directly instead of dispatching the job.
        $existing = SimilarityCheck::create([
            'user_id' => auth()->id(),
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'status' => 'running',
            'threshold' => 80,
            'team_count' => 2,
            'snapshot' => ['run_ids' => Run::where('problem_id', $problem->id)->pluck('id')->sort()->values()->all(), 'source_hashes' => [], 'excluded' => []],
        ]);

        $response = $this->postJson('/api/frontend/similarity/checks', $payload, $this->idempotencyHeader())->assertStatus(409);
        $response->assertJsonPath('data.id', $existing->id);
        $this->assertSame(1, SimilarityCheck::count());
    }

    public function test_concurrency_cap_per_contest_is_enforced(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $this->acceptedRun($contest, $problem, $language, 'a');
        $this->acceptedRun($contest, $problem, $language, 'b');
        $admin = $this->createAdminUser();

        // Fill the default cap (2, see config/similarity.php) with unrelated
        // in-flight checks for the SAME contest so the next request must be
        // rejected purely on the concurrency count, independent of the
        // duplicate-snapshot check.
        for ($i = 0; $i < 2; $i++) {
            SimilarityCheck::create([
                'user_id' => $admin->user_id,
                'contest_id' => $contest->id,
                'problem_id' => $problem->id,
                'language_id' => $language->id,
                'status' => 'running',
                'threshold' => 80,
                'team_count' => 2,
                'snapshot' => ['run_ids' => [], 'source_hashes' => [], 'excluded' => []],
            ]);
        }

        $this->actingAs($admin);
        $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'threshold' => 80,
        ], $this->idempotencyHeader())->assertStatus(409);

        $this->assertSame(2, SimilarityCheck::count());
    }

    /**
     * capabilities.can_start must reflect the same concurrency condition
     * store() actually enforces -- otherwise the UI offers a submit that
     * predictably 409s instead of disabling it up front.
     */
    public function test_index_reports_can_start_false_when_every_contest_is_at_the_concurrency_cap(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $admin = $this->createAdminUser();

        for ($i = 0; $i < 2; $i++) {
            SimilarityCheck::create([
                'user_id' => $admin->user_id,
                'contest_id' => $contest->id,
                'problem_id' => $problem->id,
                'language_id' => $language->id,
                'status' => 'running',
                'threshold' => 80,
                'team_count' => 2,
                'snapshot' => ['run_ids' => [], 'source_hashes' => [], 'excluded' => []],
            ]);
        }

        $this->actingAs($admin);
        $this->getJson('/api/frontend/similarity')->assertOk()->assertJsonPath('data.capabilities.can_start', false);

        // A second, unrelated contest with room under the cap flips it back to true.
        [$otherContest, $otherProblem, $otherLanguage] = $this->makeContestProblemLanguage();
        $this->getJson('/api/frontend/similarity')->assertOk()->assertJsonPath('data.capabilities.can_start', true);
        $this->assertNotNull($otherProblem);
        $this->assertNotNull($otherLanguage);
    }

    public function test_admin_can_download_source_but_participant_cannot_guess_the_url(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $run = $this->acceptedRun($contest, $problem, $language, 'source contents');

        $this->actingAs($this->createAdminUser());
        $this->get("/api/frontend/similarity/runs/{$run->id}/source")->assertOk();

        $this->actingAs($this->createTestUser());
        $this->get("/api/frontend/similarity/runs/{$run->id}/source")->assertForbidden();
    }

    /**
     * If enqueueing itself throws (e.g. the queue backend is briefly
     * unreachable) after the SimilarityCheck row already committed, the
     * check must not be left stuck at 'queued' forever with no job ever
     * having been attempted.
     */
    public function test_dispatch_failure_marks_the_check_failed_instead_of_leaving_it_stuck_queued(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $this->acceptedRun($contest, $problem, $language, 'a');
        $this->acceptedRun($contest, $problem, $language, 'b');

        $this->app->bind(BusDispatcher::class, fn () => new class implements BusDispatcher {
            public function dispatch($command)
            {
                throw new \RuntimeException('queue unreachable');
            }

            public function dispatchSync($command, $handler = null)
            {
                throw new \RuntimeException('queue unreachable');
            }

            public function dispatchNow($command, $handler = null)
            {
                throw new \RuntimeException('queue unreachable');
            }

            public function dispatchAfterResponse($command, $handler = null) {}

            public function chain($jobs = null) {}

            public function hasCommandHandler($command)
            {
                return false;
            }

            public function getCommandHandler($command)
            {
                return false;
            }

            public function pipeThrough(array $pipes)
            {
                return $this;
            }

            public function map(array $map)
            {
                return $this;
            }
        });

        $this->actingAs($this->createAdminUser());
        $response = $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'threshold' => 80,
        ], $this->idempotencyHeader())->assertStatus(202);

        $response->assertJsonPath('data.status', 'failed');
        $check = SimilarityCheck::findOrFail($response->json('data.id'));
        $this->assertSame('failed', $check->status);
        $this->assertSame('dispatch_failed', $check->safe_error_code);
    }

    /**
     * The job's failed() callback must not blame every non-timeout
     * interruption (worker OOM-killed, lost DB connection, etc.) on a
     * timeout it never actually saw -- that would show the admin a
     * misleading "excedeu o tempo limite" message.
     */
    public function test_failed_callback_labels_a_non_timeout_interruption_generically(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $run1 = $this->acceptedRun($contest, $problem, $language, 'a');
        $run2 = $this->acceptedRun($contest, $problem, $language, 'b');

        $check = SimilarityCheck::create([
            'user_id' => 1,
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'status' => 'running',
            'threshold' => 80,
            'team_count' => 2,
            'snapshot' => ['run_ids' => [$run1->id, $run2->id], 'source_hashes' => [], 'excluded' => []],
        ]);

        (new RunSimilarityCheckJob($check))->failed(new \RuntimeException('worker was OOM-killed'));

        $check->refresh();
        $this->assertSame('failed', $check->status);
        $this->assertSame('worker_interrupted', $check->safe_error_code);
        $this->assertNotSame('A análise excedeu o tempo limite e foi interrompida.', $check->safeErrorMessage());
    }
}
