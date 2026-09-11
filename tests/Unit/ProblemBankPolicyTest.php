<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProblemBank;
use App\Policies\ProblemBankPolicy;
use Tests\TestCase;

/**
 * Issue #46 -- exercises the policy directly (not just through the new
 * /api/frontend/bank-governance HTTP endpoints), because this is the same
 * policy class the legacy toggle()/destroy()/import routes now call
 * (app/Http/Controllers/Backend/ProblemBankController.php,
 * routes/web.php's /import-boca/* closures). Proving it here demonstrates
 * the ownership boundary holds independent of which controller invokes it.
 */
class ProblemBankPolicyTest extends TestCase
{
    private ProblemBankPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ProblemBankPolicy;
    }

    public function test_admin_bypasses_every_check(): void
    {
        $admin = $this->createAdminUser();
        $legacy = ProblemBank::create($this->bankAttributes());
        $org = Organization::create(['name' => 'Org A']);
        $owned = ProblemBank::create($this->bankAttributes(['owning_org_id' => $org->id]));

        $this->assertTrue($this->policy->create($admin));
        $this->assertTrue($this->policy->update($admin, $legacy));
        $this->assertTrue($this->policy->update($admin, $owned));
        $this->assertTrue($this->policy->transfer($admin, $legacy));
        $this->assertTrue($this->policy->delete($admin, $legacy));
    }

    public function test_non_admin_cannot_touch_a_legacy_item(): void
    {
        $user = $this->createTestUser(['user_type' => 'team']);
        $legacy = ProblemBank::create($this->bankAttributes());

        $this->assertFalse($this->policy->create($user));
        $this->assertFalse($this->policy->update($user, $legacy));
        $this->assertFalse($this->policy->transfer($user, $legacy));
        $this->assertFalse($this->policy->delete($user, $legacy));
    }

    public function test_editor_can_update_but_not_transfer_or_delete_their_own_organizations_item(): void
    {
        $org = Organization::create(['name' => 'Org A']);
        $editor = $this->createTestUser(['user_type' => 'team']);
        OrganizationMembership::create([
            'organization_id' => $org->id,
            'user_id' => $editor->user_id,
            'role' => OrganizationMembership::ROLE_EDITOR,
        ]);
        $owned = ProblemBank::create($this->bankAttributes(['owning_org_id' => $org->id]));

        $this->assertTrue($this->policy->update($editor, $owned));
        $this->assertFalse($this->policy->transfer($editor, $owned));
        $this->assertFalse($this->policy->delete($editor, $owned));
        $this->assertFalse($this->policy->create($editor));
    }

    public function test_editor_cannot_update_another_organizations_item(): void
    {
        $orgA = Organization::create(['name' => 'Org A']);
        $orgB = Organization::create(['name' => 'Org B']);
        $editorA = $this->createTestUser(['user_type' => 'team']);
        OrganizationMembership::create([
            'organization_id' => $orgA->id,
            'user_id' => $editorA->user_id,
            'role' => OrganizationMembership::ROLE_EDITOR,
        ]);
        $itemB = ProblemBank::create($this->bankAttributes(['owning_org_id' => $orgB->id]));

        $this->assertFalse($this->policy->update($editorA, $itemB));
    }

    private function bankAttributes(array $overrides = []): array
    {
        return array_merge([
            'code' => 'CODE'.rand(10000, 99999),
            'name' => 'Problema '.rand(10000, 99999),
            'description' => 'desc',
            'input_description' => 'in',
            'output_description' => 'out',
            'difficulty' => 'easy',
            'tags' => [],
            'is_active' => true,
        ], $overrides);
    }
}
