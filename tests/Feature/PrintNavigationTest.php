<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Site;
use Helium\User;
use Tests\TestCase;

/**
 * Issue #161 -- printing (#94) shipped with no way to reach it.
 *
 * The controller, the route, the config and the screen all worked; no view
 * linked to them. `grep -rn "href=\"/print\|route('print" resources/views`
 * found exactly one hit, and it was the page's own form posting to itself.
 * A feature nobody can navigate to is a feature nobody has, and the whole
 * suite stays green while that is true -- which is why this test asserts on
 * the navigation rather than on the page.
 */
class PrintNavigationTest extends TestCase
{
    private function team(): User
    {
        $contest = Contest::factory()->create(['is_active' => true]);
        $site = Site::factory()->create(['contest_id' => $contest->id]);

        return $this->createTestUser([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'user_type' => 'team',
        ]);
    }

    public function test_a_team_can_navigate_to_printing(): void
    {
        $this->actingAs($this->team())->get('/home')->assertOk()->assertSee('href="/print"', false);
    }

    /**
     * And the link has to lead somewhere the team may actually go, or it is
     * a worse bug than the missing link: a dead end in the sidebar.
     */
    public function test_the_link_leads_to_a_page_the_team_may_open(): void
    {
        $this->actingAs($this->team())->get('/print')->assertOk();
    }

    public function test_a_judge_is_not_offered_printing(): void
    {
        // Printing is the team's channel; the controller already refuses a
        // judge with 403, so offering the link would be an invitation to it.
        $judge = $this->createTestUser(['user_type' => 'judge']);

        $this->actingAs($judge)->get('/home')->assertOk()->assertDontSee('href="/print"', false);
    }
}
