<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Language;
use App\Models\Site;
use Tests\TestCase;

class FrontendReleaseAuditTest extends TestCase
{
    public function test_operations_require_admin_and_hide_awards_until_finalized(): void
    {
        $contest = Contest::factory()->finished()->create();
        $url = route('backend.contest.operations', $contest);
        $this->get($url)->assertRedirect('/login');
        $this->actingAs($this->createTestUser())->get($url)->assertForbidden();
        $this->actingAs($this->createAdminUser())->get($url)->assertOk()
            ->assertSee('Conferir pendências')->assertSee('Placar')->assertDontSee('<table', false);
    }

    public function test_finalize_rechecks_server_state_and_duplicate_submit_is_idempotent(): void
    {
        $contest = Contest::factory()->running()->create();
        $this->actingAs($this->createAdminUser());
        $url = route('backend.contest.operations', $contest);
        $this->from($url)->post(route('backend.contest.finalize', $contest))->assertRedirect($url)->assertSessionHas('error');
        $this->assertNull($contest->fresh()->finalized_at);
        $contest->update(['start_time' => now()->subHours(8), 'unfrozen_at' => now()]);
        $this->from($url)->post(route('backend.contest.finalize', $contest))->assertRedirect($url)->assertSessionHas('success');
        $stamp = $contest->fresh()->finalized_at;
        $this->assertNotNull($stamp);
        $this->travel(5)->minutes();
        $this->from($url)->post(route('backend.contest.finalize', $contest))->assertRedirect($url)->assertSessionHas('success');
        $this->assertTrue($contest->fresh()->finalized_at->equalTo($stamp));
        $this->get($url)->assertOk()->assertSee('Finalizada em');
    }

    public function test_scheduled_contest_cannot_be_finalized_through_the_new_screen(): void
    {
        $contest = Contest::factory()->notStarted()->create();
        $this->actingAs($this->createAdminUser());
        $this->get(route('backend.contest.operations', $contest))->assertOk()->assertSee('ainda não começou');
        $this->from(route('backend.contest.operations', $contest))->post(route('backend.contest.finalize', $contest))->assertSessionHas('error');
        $this->assertNull($contest->fresh()->finalized_at);
    }

    public function test_unfreeze_refuses_live_contest_and_reuses_existing_release_operation(): void
    {
        $contest = Contest::factory()->running()->create();
        $this->actingAs($this->createAdminUser());
        $url = route('backend.contest.operations', $contest);
        $this->from($url)->post(route('backend.contest.unfreeze', $contest))->assertSessionHas('error');
        $this->assertNull($contest->fresh()->unfrozen_at);
        $contest->update(['start_time' => now()->subHours(8)]);
        $this->from($url)->post(route('backend.contest.unfreeze', $contest))->assertRedirect($url)->assertSessionHas('success');
        $this->assertNotNull($contest->fresh()->unfrozen_at);
    }

    public function test_edit_values_are_scoped_to_submitted_row_and_modals_are_outside_table(): void
    {
        $contest = Contest::factory()->create();
        $first = Site::factory()->create(['contest_id' => $contest->id, 'name' => 'Sede Norte']);
        $second = Site::factory()->create(['contest_id' => $contest->id, 'name' => 'Sede Sul']);
        $response = $this->actingAs($this->createAdminUser())->withSession(['_old_input' => ['_form_key' => 'edit'.$second->id, 'name' => 'Tentativa', 'max_judge_wait_time' => 73]])
            ->get(route('backend.sites', ['contest_id' => $contest->id]))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame('Sede Norte', $dom->getElementById('edit'.$first->id.'_name')->getAttribute('value'));
        $this->assertSame('Tentativa', $dom->getElementById('edit'.$second->id.'_name')->getAttribute('value'));
        $this->assertSame('73', $dom->getElementById('edit'.$second->id.'_max_judge_wait_time')->getAttribute('value'));
        $this->assertSame(0, $xpath->query('//tbody//*[@data-dialog]')->length);
        $this->assertSame(0, $xpath->query('//*[@onclick or @onsubmit or @onchange or @oninput]')->length);
    }

    public function test_language_edit_preserves_other_rows_and_unchecked_state(): void
    {
        $contest = Contest::factory()->create();
        $first = Language::factory()->create(['contest_id' => $contest->id, 'name' => 'C', 'extension' => 'c']);
        $second = Language::factory()->create(['contest_id' => $contest->id, 'name' => 'Python', 'extension' => 'py']);
        $response = $this->actingAs($this->createAdminUser())->withSession(['_old_input' => ['_form_key' => 'edit'.$second->id, 'name' => 'Tentativa']])
            ->get(route('backend.languages', ['contest_id' => $contest->id]))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame('C', $dom->getElementById('edit'.$first->id.'_name')->getAttribute('value'));
        $this->assertSame('Tentativa', $dom->getElementById('edit'.$second->id.'_name')->getAttribute('value'));
        $this->assertSame(0, $xpath->query('//form[@data-form-key="edit'.$second->id.'"]//input[@name="is_active" and @checked]')->length);
    }
}
