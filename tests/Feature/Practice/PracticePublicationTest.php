<?php

namespace Tests\Feature\Practice;

use App\Models\Contest;
use App\Models\PracticePublication;
use App\Models\Problem;
use App\Models\ProblemBank;
use Helium\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Issue #43 -- publishing and withdrawing bank entries in the practice
 * library (docs/specs/43-practice.md), through
 * POST /api/frontend/bank-governance/{id}/practice.
 */
class PracticePublicationTest extends TestCase
{
    private function bank(array $attributes = []): ProblemBank
    {
        return ProblemBank::create(array_merge([
            'code' => 'CODE'.rand(10000, 99999),
            'name' => 'Problema '.rand(10000, 99999),
            'description' => 'Some a e b.',
            'input_description' => 'Dois inteiros.',
            'output_description' => 'A soma.',
            'sample_input' => "3 5\n",
            'sample_output' => "8\n",
            'time_limit' => 1,
            'memory_limit' => 256,
            'difficulty' => 'easy',
            'tags' => ['soma'],
            'is_active' => true,
            'version' => 1,
        ], $attributes));
    }

    private function publish(User $actor, ProblemBank $bank, bool $published = true, ?string $version = null)
    {
        return $this->actingAs($actor)->postJson(
            "/api/frontend/bank-governance/{$bank->id}/practice",
            ['published' => $published, 'version' => $version ?? (string) $bank->version],
            ['Idempotency-Key' => (string) Str::uuid()],
        );
    }

    public function test_admin_publishes_a_bank_entry_and_gets_the_snapshot_id(): void
    {
        $admin = $this->createAdminUser();
        $bank = $this->bank();

        $response = $this->publish($admin, $bank)->assertOk();

        $data = $response->json('data');
        $this->assertTrue($data['published']);
        $this->assertSame('1', $data['version']);
        $this->assertNotNull($data['practice_problem_id']);

        $problem = Problem::findOrFail($data['practice_problem_id']);
        $this->assertTrue($problem->contest->is_practice);
        $this->assertSame($bank->name, $problem->name);
        // The sample travels with the snapshot as a real test case, which is
        // what makes the published problem judgeable at all.
        $this->assertSame(1, $problem->testCases()->count());
    }

    public function test_a_non_admin_editor_cannot_publish(): void
    {
        $editor = $this->createTestUser(['user_type' => 'team']);
        $bank = $this->bank();

        $this->publish($editor, $bank)->assertForbidden();
        $this->assertSame(0, PracticePublication::count());
    }

    public function test_incomplete_material_is_rejected_with_422(): void
    {
        $admin = $this->createAdminUser();
        $bank = $this->bank(['sample_input' => '', 'sample_output' => '']);

        $this->publish($admin, $bank)
            ->assertStatus(422)
            ->assertJsonValidationErrors('published');

        $this->assertSame(0, PracticePublication::count());
    }

    public function test_a_stale_version_is_rejected_with_409(): void
    {
        $admin = $this->createAdminUser();
        $bank = $this->bank(['version' => 4]);

        $this->publish($admin, $bank, true, '3')->assertStatus(409);
        $this->assertSame(0, PracticePublication::count());
    }

    public function test_editing_the_bank_after_publication_does_not_change_the_published_challenge(): void
    {
        $admin = $this->createAdminUser();
        $bank = $this->bank(['name' => 'Original', 'time_limit' => 1]);

        $problemId = $this->publish($admin, $bank)->json('data.practice_problem_id');

        // "Editar o banco depois não altera desafios ou resultados
        // existentes."
        $bank->update(['name' => 'Reescrito', 'time_limit' => 9, 'version' => 2]);

        $snapshot = Problem::findOrFail($problemId);
        $this->assertSame('Original', $snapshot->name);
        $this->assertSame(1, $snapshot->time_limit);

        $this->assertSame('Original', $this->getJson("/api/frontend/practice/problems/{$problemId}")
            ->assertOk()->json('problem.name'));
    }

    public function test_republishing_a_new_version_opens_a_new_snapshot_and_leaves_the_old_one_intact(): void
    {
        $admin = $this->createAdminUser();
        $bank = $this->bank(['name' => 'Original']);

        $firstId = $this->publish($admin, $bank)->json('data.practice_problem_id');

        $bank->update(['name' => 'Versao 2', 'version' => 2]);
        $secondId = $this->publish($admin, $bank->fresh())->json('data.practice_problem_id');

        $this->assertNotSame($firstId, $secondId);

        // The old publication is closed, not deleted: runs judged against it
        // keep pointing at the material they actually saw.
        $publications = PracticePublication::orderBy('id')->get();
        $this->assertCount(2, $publications);
        $this->assertNotNull($publications[0]->unpublished_at);
        $this->assertNull($publications[1]->unpublished_at);

        $this->assertSame('Original', Problem::findOrFail($firstId)->name);
        $this->assertSame('Versao 2', Problem::findOrFail($secondId)->name);
    }

    public function test_publishing_identical_material_twice_does_not_fork_the_library(): void
    {
        $admin = $this->createAdminUser();
        $bank = $this->bank();

        $first = $this->publish($admin, $bank)->json('data.practice_problem_id');
        $second = $this->publish($admin, $bank->fresh())->json('data.practice_problem_id');

        $this->assertSame($first, $second);
        $this->assertSame(1, PracticePublication::count());
    }

    public function test_withdrawing_keeps_the_snapshot_and_removes_it_from_the_library(): void
    {
        $admin = $this->createAdminUser();
        $bank = $this->bank();
        $problemId = $this->publish($admin, $bank)->json('data.practice_problem_id');

        $response = $this->publish($admin, $bank->fresh(), false)->assertOk();
        $this->assertFalse($response->json('data.published'));

        // "Retirar da biblioteca bloqueia novos envios, preservando
        // histórico privado" -- the snapshot row survives.
        $this->assertNotNull(Problem::find($problemId));
        $this->assertSame(0, $this->getJson('/api/frontend/practice/problems')->json('meta.total'));
    }

    public function test_bank_listing_reports_the_real_publication_status(): void
    {
        $admin = $this->createAdminUser();
        $bank = $this->bank();

        $item = fn () => collect($this->actingAs($admin)->getJson('/api/frontend/bank-governance')->json('data.items'))
            ->firstWhere('id', $bank->id);

        $this->assertSame('unpublished', $item()['practice_status']);

        $this->publish($admin, $bank);
        $this->assertSame('published', $item()['practice_status']);

        // A bank edit after publication leaves the library serving the older
        // snapshot on purpose; the listing says so rather than pretending
        // they are in sync.
        $bank->update(['version' => 2]);
        $this->assertSame('outdated', $item()['practice_status']);
    }

    public function test_reading_the_empty_library_does_not_provision_a_practice_contest(): void
    {
        $this->getJson('/api/frontend/practice/problems')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('items', []);

        $this->assertSame(0, Contest::query()->practice()->count());
    }
}
