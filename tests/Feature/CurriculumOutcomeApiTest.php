<?php

namespace Tests\Feature;

use App\Models\CurriculumFramework;
use App\Models\CurriculumOutcome;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProblemBank;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Issue #396 -- relações currículo/habilidade/problema, a associação pela
 * governança do banco e a exibição no Treino Livre
 * (docs/specs/396-curriculos-oficiais.md).
 */
class CurriculumOutcomeApiTest extends TestCase
{
    private function bank(array $attributes = []): ProblemBank
    {
        return ProblemBank::factory()->create(array_merge(['version' => 1], $attributes));
    }

    private function patchBank(ProblemBank $bank, array $payload)
    {
        return $this->patchJson("/api/frontend/bank-governance/{$bank->id}", array_merge([
            'owning_org_id' => $bank->owning_org_id,
            'tags' => [],
            'version' => (string) $bank->fresh()->version,
        ], $payload));
    }

    // -- relações ------------------------------------------------------

    public function test_framework_outcome_and_problem_relations_work_both_ways(): void
    {
        $framework = CurriculumFramework::factory()->create();
        $second = CurriculumOutcome::factory()->for($framework, 'framework')->create(['position' => 2]);
        $first = CurriculumOutcome::factory()->for($framework, 'framework')->create(['position' => 1]);
        $bank = $this->bank();

        $bank->outcomes()->attach([$second->id, $first->id]);

        $this->assertSame([$first->id, $second->id], $framework->outcomes()->pluck('id')->all(), 'ordem do documento');
        $this->assertSame([$first->id, $second->id], $bank->outcomes()->pluck('curriculum_outcomes.id')->all());
        $this->assertSame([$bank->id], $first->problemBanks()->pluck('problem_bank.id')->all());
        $this->assertTrue($first->framework->is($framework));
    }

    public function test_one_problem_can_carry_outcomes_from_two_curricula(): void
    {
        $bncc = CurriculumOutcome::factory()->create(['code' => 'EF06CO02']);
        $other = CurriculumOutcome::factory()->create(['code' => 'KS3-3']);
        $bank = $this->bank();
        $bank->outcomes()->attach([$bncc->id, $other->id]);

        $this->assertEqualsCanonicalizing(['EF06CO02', 'KS3-3'], $bank->outcomes()->pluck('code')->all());
        $this->assertNotSame($bncc->curriculum_framework_id, $other->curriculum_framework_id);
    }

    public function test_deleting_a_problem_or_a_framework_removes_the_links_only(): void
    {
        $outcome = CurriculumOutcome::factory()->create();
        $bank = $this->bank();
        $bank->outcomes()->attach($outcome);

        $bank->delete();
        $this->assertDatabaseMissing('problem_bank_outcomes', ['curriculum_outcome_id' => $outcome->id]);
        $this->assertDatabaseHas('curriculum_outcomes', ['id' => $outcome->id]);

        $kept = $this->bank();
        $kept->outcomes()->attach($outcome);
        $outcome->framework->delete();
        $this->assertDatabaseMissing('curriculum_outcomes', ['id' => $outcome->id]);
        $this->assertDatabaseMissing('problem_bank_outcomes', ['problem_bank_id' => $kept->id]);
        $this->assertDatabaseHas('problem_bank', ['id' => $kept->id]);
    }

    public function test_code_is_unique_per_framework_not_globally(): void
    {
        $a = CurriculumOutcome::factory()->create(['code' => 'X1']);
        CurriculumOutcome::factory()->create(['code' => 'X1']);

        $this->expectException(QueryException::class);
        CurriculumOutcome::factory()->for($a->framework, 'framework')->create(['code' => 'X1']);
    }

    // -- GET /api/frontend/curricula ------------------------------------

    public function test_curricula_listing_requires_login(): void
    {
        $this->getJson('/api/frontend/curricula')->assertUnauthorized();
    }

    public function test_curricula_listing_returns_frameworks_with_outcomes_in_document_order(): void
    {
        $this->artisan('curriculum:import', ['file' => 'database/curricula/bncc-computacao-2022.csv'])->assertSuccessful();

        $response = $this->actingAs($this->createTestUser(['user_type' => 'team']))
            ->getJson('/api/frontend/curricula')
            ->assertOk();

        $framework = $response->json('data.frameworks.0');
        $this->assertSame('bncc-computacao', $framework['slug']);
        $this->assertSame('2026-09-24', $framework['source_consulted_at']);
        $this->assertCount(141, $framework['outcomes']);
        $this->assertSame('EI03CO01', $framework['outcomes'][0]['code']);
        $this->assertSame(['id', 'code', 'stage', 'axis', 'text'], array_keys($framework['outcomes'][0]));
    }

    // -- governança do banco --------------------------------------------

    public function test_bank_governance_lists_the_outcomes_of_each_problem(): void
    {
        $outcome = CurriculumOutcome::factory()->create(['code' => 'EF06CO02', 'stage' => '6º ano']);
        $bank = $this->bank();
        $bank->outcomes()->attach($outcome);

        $item = collect($this->actingAs($this->createAdminUser())->getJson('/api/frontend/bank-governance')->assertOk()->json('data.items'))
            ->firstWhere('id', $bank->id);

        $this->assertSame('EF06CO02', $item['outcomes'][0]['code']);
        $this->assertSame('6º ano', $item['outcomes'][0]['stage']);
        $this->assertSame($outcome->framework->slug, $item['outcomes'][0]['framework']['slug']);
    }

    public function test_editor_sets_and_replaces_the_outcomes_of_a_problem(): void
    {
        $org = Organization::create(['name' => 'Escola']);
        $editor = $this->createTestUser(['user_type' => 'team']);
        OrganizationMembership::create(['organization_id' => $org->id, 'user_id' => $editor->user_id, 'role' => OrganizationMembership::ROLE_EDITOR]);
        [$a, $b, $c] = CurriculumOutcome::factory()->count(3)->create();
        $bank = $this->bank(['owning_org_id' => $org->id]);

        $this->actingAs($editor);
        $this->patchBank($bank, ['outcome_ids' => [$a->id, (string) $b->id]])->assertOk()->assertJsonPath('data.version', '2');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $bank->outcomes()->pluck('curriculum_outcomes.id')->all());

        $this->patchBank($bank, ['outcome_ids' => [$c->id]])->assertOk();
        $this->assertSame([$c->id], $bank->outcomes()->pluck('curriculum_outcomes.id')->all());

        $this->patchBank($bank, ['outcome_ids' => []])->assertOk();
        $this->assertSame(0, $bank->outcomes()->count());
    }

    public function test_omitting_outcome_ids_leaves_the_links_untouched(): void
    {
        $outcome = CurriculumOutcome::factory()->create();
        $bank = $this->bank();
        $bank->outcomes()->attach($outcome);

        $this->actingAs($this->createAdminUser());
        $this->patchBank($bank, ['tags' => ['grafos']])->assertOk();

        $this->assertSame([$outcome->id], $bank->outcomes()->pluck('curriculum_outcomes.id')->all());
        $this->assertSame(['grafos'], $bank->fresh()->tags);
    }

    public function test_invalid_outcome_ids_are_refused_without_writing_anything(): void
    {
        $outcome = CurriculumOutcome::factory()->create();
        $bank = $this->bank(['tags' => ['antes']]);
        $this->actingAs($this->createAdminUser());

        foreach ([
            [[999999], 'Habilidade inexistente. Atualize a página.'],
            [[$outcome->id, $outcome->id], 'Habilidade repetida.'],
            [['abc'], 'Habilidades inválidas.'],
            [[1.5], 'Habilidades inválidas.'],
            ['1', 'Habilidades inválidas.'],
            [['x' => $outcome->id], 'Habilidades inválidas.'],
            [range(1, 51), 'No máximo 50 habilidades por problema.'],
        ] as [$ids, $message]) {
            $this->patchBank($bank, ['tags' => ['depois'], 'outcome_ids' => $ids])
                ->assertStatus(422)
                ->assertJsonPath('errors.outcome_ids.0', $message);
        }

        $bank->refresh();
        $this->assertSame(['antes'], $bank->tags);
        $this->assertSame(1, $bank->version);
        $this->assertSame(0, $bank->outcomes()->count());
    }

    public function test_stale_version_and_foreign_editor_cannot_change_outcomes(): void
    {
        $outcome = CurriculumOutcome::factory()->create();
        $org = Organization::create(['name' => 'Outra']);
        $bank = $this->bank(['owning_org_id' => $org->id, 'version' => 3]);

        $this->actingAs($this->createAdminUser());
        $this->patchBank($bank, ['outcome_ids' => [$outcome->id], 'version' => '2'])->assertStatus(409);

        $stranger = $this->createTestUser(['user_type' => 'team']);
        $this->actingAs($stranger);
        $this->patchBank($bank, ['outcome_ids' => [$outcome->id]])->assertForbidden();

        $this->assertSame(0, $bank->outcomes()->count());
    }

    // -- Treino Livre ---------------------------------------------------

    private function publish(ProblemBank $bank): int
    {
        config(['autojudge.use_bwrap' => true, 'autojudge.bwrap_path' => '/bin/sh']);
        $id = (int) $this->actingAs($this->createAdminUser())->postJson(
            "/api/frontend/bank-governance/{$bank->id}/practice",
            ['published' => true, 'version' => (string) $bank->fresh()->version],
            ['Idempotency-Key' => (string) Str::uuid()],
        )->assertOk()->json('data.practice_problem_id');

        Auth::logout();
        $this->app['auth']->forgetGuards();

        return $id;
    }

    public function test_practice_problem_shows_its_skills_to_anonymous_readers(): void
    {
        $framework = CurriculumFramework::factory()->create(['name' => 'BNCC Computação']);
        $outcome = CurriculumOutcome::factory()->for($framework, 'framework')->create([
            'code' => 'EF06CO05',
            'text' => 'Identificar os recursos ou insumos necessários (entradas).',
            'stage' => '6º ano',
            'axis' => 'Pensamento Computacional',
        ]);
        $bank = $this->bank(['sample_input' => "1\n", 'sample_output' => "1\n"]);
        $bank->outcomes()->attach($outcome);
        $problemId = $this->publish($bank);

        $skills = $this->getJson("/api/frontend/practice/problems/{$problemId}")->assertOk()->json('problem.skills');

        $this->assertSame([[
            'id' => $outcome->id,
            'code' => 'EF06CO05',
            'stage' => '6º ano',
            'axis' => 'Pensamento Computacional',
            'text' => 'Identificar os recursos ou insumos necessários (entradas).',
            'framework' => ['slug' => $framework->slug, 'name' => 'BNCC Computação'],
        ]], $skills);
    }

    public function test_practice_library_lists_skill_codes_and_filters_by_code(): void
    {
        $ef06 = CurriculumOutcome::factory()->create(['code' => 'EF06CO02']);
        $ef07 = CurriculumOutcome::factory()->create(['code' => 'EF07CO02']);
        $withSkill = $this->bank(['name' => 'A com habilidade', 'sample_input' => "1\n", 'sample_output' => "1\n"]);
        $withSkill->outcomes()->attach([$ef06->id, $ef07->id]);
        $withoutSkill = $this->bank(['name' => 'B sem habilidade', 'sample_input' => "1\n", 'sample_output' => "1\n"]);
        $withSkillId = $this->publish($withSkill);
        $this->publish($withoutSkill);

        $all = $this->getJson('/api/frontend/practice/problems')->assertOk()->json('items');
        $this->assertCount(2, $all);
        $this->assertSame(['EF06CO02', 'EF07CO02'], collect($all)->firstWhere('id', $withSkillId)['skills']);
        $this->assertSame([], collect($all)->firstWhere('name', 'B sem habilidade')['skills']);

        $filtered = $this->getJson('/api/frontend/practice/problems?skill=EF07CO02')->assertOk();
        $this->assertSame([$withSkillId], collect($filtered->json('items'))->pluck('id')->all());
        $this->assertSame(1, $filtered->json('meta.total'));

        $this->assertSame([], $this->getJson('/api/frontend/practice/problems?skill=EF09CO01')->assertOk()->json('items'));

        // Formato inválido vira "sem filtro", como `q` malformado.
        $this->assertCount(2, $this->getJson('/api/frontend/practice/problems?skill[]=x')->assertOk()->json('items'));
    }
}
