<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\SimilarityCheck;
use App\Models\SimilarityPair;
use App\Services\Similarity\SimilarityEngineInterface;
use App\Jobs\RunSimilarityCheckJob;
use Helium\User;
use Illuminate\Support\Facades\Cache;
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
        ])->assertForbidden();
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

    public function test_store_rejects_unsupported_language_with_422_on_language_field(): void
    {
        [$contest, $problem] = $this->makeContestProblemLanguage();
        $unsupported = Language::factory()->create(['contest_id' => $contest->id, 'name' => 'Brainfuck', 'extension' => 'bf']);

        $this->actingAs($this->createAdminUser());
        $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $unsupported->id,
            'threshold' => 80,
        ])->assertStatus(422)->assertJsonValidationErrors('language_id');

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
        ])->assertStatus(422)->assertJsonValidationErrors('threshold');
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
        ])->assertStatus(422)->assertJsonValidationErrors('language_id');

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
        ])->assertStatus(202);

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
        ])->assertStatus(202);

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
        ])->assertStatus(202);

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
        $fake->throws = new \App\Services\Similarity\SimilarityEngineException('engine_timeout', 'boom');

        $this->acceptedRun($contest, $problem, $language, 'a');
        $this->acceptedRun($contest, $problem, $language, 'b');

        $this->actingAs($this->createAdminUser());
        $response = $this->postJson('/api/frontend/similarity/checks', [
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'threshold' => 80,
        ])->assertStatus(202);

        $check = SimilarityCheck::findOrFail($response->json('data.id'));
        $this->assertSame('failed', $check->status);
        $this->assertNotNull($check->safeErrorMessage());

        $index = $this->getJson('/api/frontend/similarity')->assertOk();
        $item = $index->json('data.items.0');
        $this->assertSame('failed', $item['status']);
        $this->assertIsString($item['error_message']);
    }

    public function test_repeating_the_same_idempotency_key_and_payload_does_not_duplicate_the_job(): void
    {
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $this->bindFakeEngine();
        $this->acceptedRun($contest, $problem, $language, 'a');
        $this->acceptedRun($contest, $problem, $language, 'b');

        $this->actingAs($this->createAdminUser());
        $payload = ['problem_id' => $problem->id, 'language_id' => $language->id, 'threshold' => 80];
        $headers = ['Idempotency-Key' => 'fixed-key-123'];

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
        $headers = ['Idempotency-Key' => 'reused-key'];

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

        // Bind an engine that never resolves during this request so the
        // check stays "running" long enough to attempt a duplicate.
        $fake = new FakeSimilarityEngine();
        $fake->behavior = function () {
            // Simulate a check that is still mid-flight: leave the row in
            // `running`, without throwing or returning, by directly
            // manipulating nothing here -- instead we pre-seed a running
            // check below and never dispatch a real second job.
            return new \App\Services\Similarity\SimilarityEngineResult([], 'fake-1.0.0');
        };
        $this->app->instance(SimilarityEngineInterface::class, $fake);

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

        $response = $this->postJson('/api/frontend/similarity/checks', $payload)->assertStatus(409);
        $response->assertJsonPath('data.id', $existing->id);
        $this->assertSame(1, SimilarityCheck::count());
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
     * Without the per-contest creation lock in SimilarityController::store(),
     * two concurrent requests (no Idempotency-Key, e.g. two admin tabs)
     * could both read zero conflicting rows before either INSERT commits
     * and both create a SimilarityCheck for the same team set -- there is
     * no DB-level unique constraint on similarity_checks to catch that.
     * A real race can't be forced deterministically in a single-threaded
     * test, so this holds the exact lock the controller uses before
     * calling it, proving the request actually waits on it instead of
     * sailing through.
     */
    public function test_concurrent_requests_for_the_same_contest_are_serialized_not_duplicated(): void
    {
        config(['similarity.lock_wait_seconds' => 1]);
        [$contest, $problem, $language] = $this->makeContestProblemLanguage();
        $this->acceptedRun($contest, $problem, $language, 'a');
        $this->acceptedRun($contest, $problem, $language, 'b');

        $lock = Cache::lock("similarity-check-create:contest:{$contest->id}", 30);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($this->createAdminUser());
            $this->postJson('/api/frontend/similarity/checks', [
                'problem_id' => $problem->id,
                'language_id' => $language->id,
                'threshold' => 80,
            ])->assertStatus(409);
        } finally {
            $lock->release();
        }

        $this->assertSame(0, SimilarityCheck::count());
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
