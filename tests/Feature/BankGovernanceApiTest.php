<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Problem;
use App\Models\ProblemBank;
use App\Models\ProblemBankOwnershipTransfer;
use Helium\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Issue #46 -- docs/specs/46-bank-ownership.md.
 * Covers the "Critérios de aceite" list plus the shared contract in
 * docs/specs/README.md (envelope, status codes, optimistic concurrency).
 */
class BankGovernanceApiTest extends TestCase
{
    private function bank(array $attributes = []): ProblemBank
    {
        return ProblemBank::create(array_merge([
            'code' => 'CODE'.rand(10000, 99999),
            'name' => 'Problema '.rand(10000, 99999),
            'description' => 'desc',
            'input_description' => 'in',
            'output_description' => 'out',
            'difficulty' => 'easy',
            'tags' => [],
            'is_active' => true,
            'version' => 1,
        ], $attributes));
    }

    private function editor(Organization $org): User
    {
        $user = $this->createTestUser(['user_type' => 'team']);
        OrganizationMembership::create([
            'organization_id' => $org->id,
            'user_id' => $user->user_id,
            'role' => OrganizationMembership::ROLE_EDITOR,
        ]);

        return $user;
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/frontend/bank-governance')->assertUnauthorized();
    }

    public function test_admin_sees_all_organizations_and_items(): void
    {
        $admin = $this->createAdminUser();
        $orgA = Organization::create(['name' => 'Org A']);
        $orgB = Organization::create(['name' => 'Org B']);
        $legacy = $this->bank(['name' => 'Legado']);
        $itemA = $this->bank(['name' => 'Item A', 'owning_org_id' => $orgA->id]);
        $itemB = $this->bank(['name' => 'Item B', 'owning_org_id' => $orgB->id]);

        $response = $this->actingAs($admin)->getJson('/api/frontend/bank-governance')->assertOk();
        $data = $response->json('data');

        $this->assertCount(2, $data['organizations']);
        $this->assertCount(3, $data['items']);
        $this->assertSame(3, $data['meta']['total']);

        $legacyItem = collect($data['items'])->firstWhere('id', $legacy->id);
        $this->assertNull($legacyItem['owning_org_id']);
        $this->assertNull($legacyItem['organization_name']);
        $this->assertTrue($legacyItem['capabilities']['can_edit']);
        $this->assertTrue($legacyItem['capabilities']['can_transfer']);
        $this->assertFalse($legacyItem['capabilities']['can_publish']);
        $this->assertSame('unpublished', $legacyItem['practice_status']);

        $itemAJson = collect($data['items'])->firstWhere('id', $itemA->id);
        $this->assertSame('Org A', $itemAJson['organization_name']);
    }

    public function test_editor_sees_only_their_organization_and_cannot_see_or_edit_others(): void
    {
        $orgA = Organization::create(['name' => 'Org A']);
        $orgB = Organization::create(['name' => 'Org B']);
        $editorA = $this->editor($orgA);
        $itemA = $this->bank(['name' => 'Item A', 'owning_org_id' => $orgA->id]);
        $itemB = $this->bank(['name' => 'Item B', 'owning_org_id' => $orgB->id]);
        $legacy = $this->bank(['name' => 'Legado']);

        $response = $this->actingAs($editorA)->getJson('/api/frontend/bank-governance')->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data['organizations']);
        $this->assertSame('Org A', $data['organizations'][0]['name']);
        $this->assertCount(1, $data['items']);
        $this->assertSame($itemA->id, $data['items'][0]['id']);
        $this->assertTrue($data['items'][0]['capabilities']['can_edit']);
        $this->assertFalse($data['items'][0]['capabilities']['can_transfer']);
    }

    public function test_user_with_no_membership_sees_empty_scope_not_an_error(): void
    {
        Organization::create(['name' => 'Org A']);
        $this->bank(['name' => 'Legado']);
        $user = $this->createTestUser(['user_type' => 'team']);

        $response = $this->actingAs($user)->getJson('/api/frontend/bank-governance')->assertOk();
        $data = $response->json('data');

        $this->assertSame([], $data['organizations']);
        $this->assertSame([], $data['items']);
        $this->assertSame(0, $data['meta']['total']);
    }

    public function test_organization_filter_and_search_do_not_leak_invisible_item_counts(): void
    {
        $orgA = Organization::create(['name' => 'Org A']);
        $orgB = Organization::create(['name' => 'Org B']);
        $editorA = $this->editor($orgA);
        $this->bank(['name' => 'Item A', 'owning_org_id' => $orgA->id]);
        $this->bank(['name' => 'Item B', 'owning_org_id' => $orgB->id]);

        // Editor A explicitly asking for org B's id: empty, not 403/404 --
        // indistinguishable from "org doesn't exist".
        $response = $this->actingAs($editorA)
            ->getJson('/api/frontend/bank-governance?organization_id='.$orgB->id)
            ->assertOk();
        $this->assertSame(0, $response->json('data.meta.total'));

        // Editor A asking for "unassigned" (legacy): also empty, legacy is
        // admin-only.
        $response = $this->actingAs($editorA)
            ->getJson('/api/frontend/bank-governance?organization_id=unassigned')
            ->assertOk();
        $this->assertSame(0, $response->json('data.meta.total'));
    }

    public function test_admin_can_assign_legacy_item_to_organization(): void
    {
        $admin = $this->createAdminUser();
        $org = Organization::create(['name' => 'Org A']);
        $legacy = $this->bank(['name' => 'Legado']);

        $response = $this->actingAs($admin)->patchJson("/api/frontend/bank-governance/{$legacy->id}", [
            'owning_org_id' => $org->id,
            'tags' => ['grafos'],
            'version' => (string) $legacy->version,
        ])->assertOk();

        $this->assertSame($legacy->id, $response->json('data.id'));
        $this->assertSame('2', $response->json('data.version'));

        $legacy->refresh();
        $this->assertSame($org->id, $legacy->owning_org_id);
        $this->assertSame(['grafos'], $legacy->tags);

        $this->assertDatabaseHas('problem_bank_ownership_transfers', [
            'problem_bank_id' => $legacy->id,
            'from_organization_id' => null,
            'to_organization_id' => $org->id,
            'actor_user_id' => $admin->user_id,
        ]);
    }

    public function test_editor_can_edit_own_organizations_tags_but_not_another_organizations_item_nor_transfer_to_self(): void
    {
        $orgA = Organization::create(['name' => 'Org A']);
        $orgB = Organization::create(['name' => 'Org B']);
        $editorA = $this->editor($orgA);
        $itemA = $this->bank(['name' => 'Item A', 'owning_org_id' => $orgA->id, 'tags' => ['old']]);
        $itemB = $this->bank(['name' => 'Item B', 'owning_org_id' => $orgB->id]);

        // Editor A can edit A's tags, sending the unchanged owning_org_id
        // (the UI always sends it, even disabled).
        $response = $this->actingAs($editorA)->patchJson("/api/frontend/bank-governance/{$itemA->id}", [
            'owning_org_id' => $orgA->id,
            'tags' => ['grafos', 'dp'],
            'version' => (string) $itemA->version,
        ])->assertOk();

        $itemA->refresh();
        $this->assertSame(['grafos', 'dp'], $itemA->tags);
        $this->assertSame($orgA->id, $itemA->owning_org_id);

        // Editor A cannot modify B's item at all (403, can_edit is false).
        $this->actingAs($editorA)->patchJson("/api/frontend/bank-governance/{$itemB->id}", [
            'owning_org_id' => $orgB->id,
            'tags' => ['x'],
            'version' => (string) $itemB->version,
        ])->assertForbidden();
        $itemB->refresh();
        $this->assertSame([], $itemB->tags);

        // Editor A cannot transfer A's own item to themself/another org,
        // even though can_edit was true for this item.
        $itemA->refresh();
        $this->actingAs($editorA)->patchJson("/api/frontend/bank-governance/{$itemA->id}", [
            'owning_org_id' => $orgB->id,
            'tags' => $itemA->tags,
            'version' => (string) $itemA->version,
        ])->assertForbidden();
        $itemA->refresh();
        $this->assertSame($orgA->id, $itemA->owning_org_id);
    }

    public function test_adding_admin_looking_tag_does_not_change_capabilities(): void
    {
        $orgA = Organization::create(['name' => 'Org A']);
        $editorA = $this->editor($orgA);
        $itemA = $this->bank(['name' => 'Item A', 'owning_org_id' => $orgA->id]);

        $this->actingAs($editorA)->patchJson("/api/frontend/bank-governance/{$itemA->id}", [
            'owning_org_id' => $orgA->id,
            'tags' => ['admin', 'root', 'superuser'],
            'version' => (string) $itemA->version,
        ])->assertOk();

        $response = $this->actingAs($editorA)->getJson('/api/frontend/bank-governance')->assertOk();
        $item = collect($response->json('data.items'))->firstWhere('id', $itemA->id);
        $this->assertSame(['admin', 'root', 'superuser'], $item['tags']);
        $this->assertTrue($item['capabilities']['can_edit']);
        $this->assertFalse($item['capabilities']['can_transfer']);
        $this->assertFalse($item['capabilities']['can_publish']);
    }

    public function test_concurrent_edits_second_gets_409_without_silently_overwriting(): void
    {
        $admin = $this->createAdminUser();
        $item = $this->bank(['name' => 'Item', 'tags' => ['a']]);
        $staleVersion = (string) $item->version;

        $this->actingAs($admin)->patchJson("/api/frontend/bank-governance/{$item->id}", [
            'owning_org_id' => null,
            'tags' => ['b'],
            'version' => $staleVersion,
        ])->assertOk();

        // Second request still carries the version it loaded with (the
        // page it came from never saw the first write).
        $this->actingAs($admin)->patchJson("/api/frontend/bank-governance/{$item->id}", [
            'owning_org_id' => null,
            'tags' => ['c'],
            'version' => $staleVersion,
        ])->assertStatus(409);

        $item->refresh();
        $this->assertSame(['b'], $item->tags, 'the stale write must not have applied any part of its payload');
    }

    public function test_tag_validation_errors_are_reported_under_the_tags_key(): void
    {
        $admin = $this->createAdminUser();
        $item = $this->bank();

        $tooMany = $this->actingAs($admin)->patchJson("/api/frontend/bank-governance/{$item->id}", [
            'owning_org_id' => null,
            'tags' => array_fill(0, 21, 'x'),
            'version' => (string) $item->version,
        ])->assertStatus(422);
        $tooMany->assertJsonValidationErrors('tags');
        $this->assertArrayNotHasKey('tags.0', $tooMany->json('errors'));

        $tooLong = $this->actingAs($admin)->patchJson("/api/frontend/bank-governance/{$item->id}", [
            'owning_org_id' => null,
            'tags' => [str_repeat('x', 41)],
            'version' => (string) $item->version,
        ])->assertStatus(422);
        $tooLong->assertJsonValidationErrors('tags');

        $tooWide = $this->actingAs($admin)->patchJson("/api/frontend/bank-governance/{$item->id}", [
            'owning_org_id' => null,
            'tags' => array_fill(0, 15, str_repeat('y', 40)),
            'version' => (string) $item->version,
        ])->assertStatus(422);
        $tooWide->assertJsonValidationErrors('tags');
    }

    public function test_tags_are_trimmed_deduplicated_case_insensitively_and_preserve_first_casing(): void
    {
        $admin = $this->createAdminUser();
        $item = $this->bank();

        $this->actingAs($admin)->patchJson("/api/frontend/bank-governance/{$item->id}", [
            'owning_org_id' => null,
            'tags' => [' Grafos ', 'grafos', 'GRAFOS', 'dp', ''],
            'version' => (string) $item->version,
        ])->assertOk();

        $item->refresh();
        $this->assertSame(['Grafos', 'dp'], $item->tags);
    }

    public function test_transferring_to_a_nonexistent_organization_is_422(): void
    {
        $admin = $this->createAdminUser();
        $item = $this->bank();

        $response = $this->actingAs($admin)->patchJson("/api/frontend/bank-governance/{$item->id}", [
            'owning_org_id' => 999999,
            'tags' => [],
            'version' => (string) $item->version,
        ])->assertStatus(422);
        $response->assertJsonValidationErrors('owning_org_id');
    }

    public function test_missing_item_is_404(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin)->patchJson('/api/frontend/bank-governance/999999', [
            'owning_org_id' => null,
            'tags' => [],
            'version' => '1',
        ])->assertNotFound();
    }

    public function test_membership_revoked_between_get_and_patch_is_blocked(): void
    {
        $org = Organization::create(['name' => 'Org A']);
        $editor = $this->editor($org);
        $item = $this->bank(['owning_org_id' => $org->id]);

        $this->actingAs($editor)->getJson('/api/frontend/bank-governance')->assertOk();

        OrganizationMembership::where('user_id', $editor->user_id)->delete();

        $this->actingAs($editor)->patchJson("/api/frontend/bank-governance/{$item->id}", [
            'owning_org_id' => $org->id,
            'tags' => ['x'],
            'version' => (string) $item->version,
        ])->assertForbidden();
    }

    public function test_bank_item_used_by_a_contest_problem_stays_intact_after_ownership_change(): void
    {
        $admin = $this->createAdminUser();
        $contestId = DB::table('contests')->insertGetId([
            'name' => 'Test Contest',
            'description' => 'Test description',
            'start_time' => now()->addHour(),
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 100,
            'is_active' => true,
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orgA = Organization::create(['name' => 'Org A']);
        $orgB = Organization::create(['name' => 'Org B']);
        $item = $this->bank(['owning_org_id' => $orgA->id, 'code' => 'ABACAXI']);

        $problem = Problem::create([
            'contest_id' => $contestId,
            'short_name' => 'A',
            'name' => 'Abacaxi',
            'basename' => Str::slug($item->code),
            'time_limit' => 1,
            'memory_limit' => 256,
            'output_limit' => 1024,
            'sort_order' => 0,
        ]);
        $before = $problem->refresh()->toArray();

        $this->actingAs($admin)->patchJson("/api/frontend/bank-governance/{$item->id}", [
            'owning_org_id' => $orgB->id,
            'tags' => ['novo'],
            'version' => (string) $item->version,
        ])->assertOk();

        $this->assertSame($before, $problem->refresh()->toArray());
    }

    public function test_legacy_toggle_and_destroy_endpoints_reapply_the_ownership_policy(): void
    {
        $admin = $this->createAdminUser();
        $item = $this->bank();

        $this->actingAs($admin)->post("/backend/problem-bank/{$item->id}/toggle")->assertRedirect();
        $item->refresh();
        $this->assertFalse($item->is_active);
        $this->assertSame(2, $item->version, 'toggle must also bump the optimistic-concurrency version');

        $this->actingAs($admin)->delete("/backend/problem-bank/{$item->id}")->assertRedirect();
        $this->assertDatabaseMissing('problem_bank', ['id' => $item->id]);
    }

    public function test_ownership_transfer_audit_row_is_written_with_correct_columns(): void
    {
        // The migration declares every FK on this table nullOnDelete() so
        // the audit row outlives the item/actor/org it references (see
        // database/migrations/..._create_problem_bank_ownership_transfers_table.php).
        // That ON DELETE behavior is enforced by the database driver itself
        // (MySQL in production) and isn't exercised here: this repo's
        // sqlite test connection (config/database.php) never sets
        // `foreign_key_constraints`, so SQLite's FK enforcement -- and
        // therefore its ON DELETE triggers -- stays off for the whole
        // suite, not just this table. This test instead just pins the
        // audit row's shape.
        $admin = $this->createAdminUser();
        $org = Organization::create(['name' => 'Org A']);
        $item = $this->bank();

        $this->actingAs($admin)->patchJson("/api/frontend/bank-governance/{$item->id}", [
            'owning_org_id' => $org->id,
            'tags' => [],
            'version' => (string) $item->version,
        ])->assertOk();

        $transfer = ProblemBankOwnershipTransfer::where('problem_bank_id', $item->id)->firstOrFail();
        $this->assertNull($transfer->from_organization_id);
        $this->assertSame($org->id, $transfer->to_organization_id);
        $this->assertSame($admin->user_id, $transfer->actor_user_id);
    }

    public function test_organization_id_as_an_array_is_treated_as_no_filter_not_a_server_error(): void
    {
        $admin = $this->createAdminUser();
        $this->bank(['name' => 'Item']);

        $this->actingAs($admin)
            ->getJson('/api/frontend/bank-governance?organization_id[]=1')
            ->assertOk();
    }
}
