<?php

namespace Tests\Feature;

use Tests\TestCase;

class FeatureWorkspaceTest extends TestCase
{
    public function test_public_practice_shell_does_not_expose_admin_navigation(): void
    {
        $this->get('/practice')->assertOk()->assertSee('data-feature-page="practice"', false)->assertDontSee('href="/backend/tools"', false);
        $this->get('/practice/problems/12')->assertOk()->assertSee('data-problem-id="12"', false);
        $this->get('/practice/problems/invalid')->assertNotFound();
        $this->get('/practice/history')->assertRedirect('/login');
    }

    public function test_admin_can_open_every_feature_shell_without_new_domain_tables(): void
    {
        $this->actingAs($this->createAdminUser());
        foreach (['tools', 'similarity', 'webcast', 'bank-governance', 'managed-accounts'] as $page) {
            $this->get('/backend/'.$page)->assertOk()->assertSee('id="main-content"', false);
        }
        $this->get('/judge/health')->assertOk();
        $this->get('/practice/history')->assertOk();
    }

    public function test_participant_cannot_open_admin_or_judge_workspaces(): void
    {
        $this->actingAs($this->createTestUser());
        foreach (['tools', 'similarity', 'webcast', 'bank-governance', 'managed-accounts'] as $page) {
            $this->get('/backend/'.$page)->assertForbidden();
        }
        $this->get('/judge/health')->assertForbidden();
        $this->get('/practice/history')->assertOk();
    }

    public function test_judge_and_site_can_open_health_but_not_admin_tools(): void
    {
        foreach (['judge', 'site'] as $role) {
            $this->actingAs($this->createTestUser(['user_type' => $role]));
            $this->get('/judge/health')->assertOk();
            $this->get('/backend/similarity')->assertForbidden();
        }
    }
}
