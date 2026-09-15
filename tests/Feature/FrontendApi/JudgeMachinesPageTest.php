<?php

namespace Tests\Feature\FrontendApi;

use Tests\TestCase;

/**
 * Issue #53, fase 4 -- the page itself, and who reaches it.
 *
 * The data routes are already gated and tested
 * (JudgehostManagementTest); this is the other half of the same gate. A
 * screen an operator can open but whose every request answers 403 is worse
 * than no screen, so the presentation route is held to the same audience
 * as routes/frontend_api_judgehosts.php -- admin, not judge.
 */
class JudgeMachinesPageTest extends TestCase
{
    public function test_an_admin_opens_the_judge_machines_screen(): void
    {
        $response = $this->actingAs($this->createTestUser(['user_type' => 'admin']))
            ->get('/backend/judge-machines');

        $response->assertStatus(200);
        // The island the bundle mounts on. Without it the page renders and
        // stays empty for ever, which is exactly the failure #166 was.
        $response->assertSee('data-feature-page="judge-machines"', false);
    }

    public function test_everyone_else_is_refused_the_screen(): void
    {
        foreach (['team', 'judge', 'staff'] as $type) {
            $this->actingAs($this->createTestUser(['user_type' => $type]))
                ->get('/backend/judge-machines')
                ->assertStatus(403);
        }

    }

    /**
     * Its own method on purpose. Asserting this at the end of the loop
     * above measured nothing: actingAs() leaves the last user authenticated
     * for the rest of the test, so the "guest" request was still the staff
     * account and answered 403 -- the right status for the wrong reason.
     */
    public function test_a_visitor_who_is_not_logged_in_is_sent_to_the_login_page(): void
    {
        $this->get('/backend/judge-machines')->assertRedirect('/login');
    }

    /**
     * The tools hub is how an organiser finds any of this. A page nothing
     * links to is a page nobody opens.
     */
    public function test_the_tools_hub_links_to_it(): void
    {
        $this->actingAs($this->createTestUser(['user_type' => 'admin']))
            ->get('/backend/tools')
            ->assertStatus(200)
            ->assertSee('/backend/judge-machines', false);
    }
}
