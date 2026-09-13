<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Site;
use App\Services\Practice\PracticeContest;
use Helium\User;
use Tests\TestCase;

/**
 * Issue #88 -- ContestLog was written in eleven places and readable in
 * none. This is the screen, and the scoping that keeps it honest.
 */
class ContestLogScreenTest extends TestCase
{
    private Contest $contest;

    private Contest $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create(['name' => 'Regional 2026']);
        $this->other = Contest::factory()->create(['name' => 'Seletiva']);
    }

    private function log(Contest $contest, string $type, string $message, array $attributes = []): ContestLog
    {
        return ContestLog::create(array_merge([
            'contest_id' => $contest->id,
            'type' => $type,
            'message' => $message,
            'ip_address' => '203.0.113.7',
        ], $attributes));
    }

    private function staffOf(Contest $contest, string $type = 'staff'): User
    {
        return User::factory()->create(['user_type' => $type, 'contest_id' => $contest->id]);
    }

    public function test_a_guest_and_a_team_cannot_read_the_log(): void
    {
        $this->get('/backend/logs')->assertRedirect();

        $this->actingAs(User::factory()->create(['user_type' => 'team', 'contest_id' => $this->contest->id]));
        $this->get('/backend/logs')->assertForbidden();
    }

    public function test_an_admin_reads_a_contests_log(): void
    {
        $this->log($this->contest, 'info', 'Run #1 submitted');
        $this->log($this->contest, 'error', 'Judging error for run #2');

        $response = $this->actingAs($this->createAdminUser())
            ->get('/backend/logs?contest_id='.$this->contest->id)
            ->assertOk();

        $response->assertSeeText('Run #1 submitted');
        $response->assertSeeText('Judging error for run #2');
    }

    public function test_a_judge_is_pinned_to_their_own_contest_whatever_the_query_says(): void
    {
        $this->log($this->contest, 'info', 'Mensagem da minha competicao');
        $this->log($this->other, 'info', 'Mensagem da outra competicao');

        $response = $this->actingAs($this->staffOf($this->contest, 'judge'))
            // Asking for the other contest by id must not get it.
            ->get('/backend/logs?contest_id='.$this->other->id)
            ->assertOk();

        $response->assertSeeText('Mensagem da minha competicao');
        $response->assertDontSeeText('Mensagem da outra competicao');
    }

    public function test_a_judge_without_a_contest_sees_nothing_rather_than_everything(): void
    {
        $this->log($this->contest, 'info', 'Mensagem de alguma competicao');

        $judge = User::factory()->create(['user_type' => 'judge', 'contest_id' => null]);

        $this->actingAs($judge)->get('/backend/logs')
            ->assertOk()
            ->assertDontSeeText('Mensagem de alguma competicao');
    }

    public function test_the_ip_column_is_admin_only(): void
    {
        $this->log($this->contest, 'info', 'Run #1 submitted');

        // An IP is personal data; auditing a verdict does not need it.
        $this->actingAs($this->createAdminUser())
            ->get('/backend/logs?contest_id='.$this->contest->id)
            ->assertOk()
            ->assertSeeText('203.0.113.7');

        $this->actingAs($this->staffOf($this->contest))
            ->get('/backend/logs')
            ->assertOk()
            ->assertDontSeeText('203.0.113.7');
    }

    public function test_the_level_filter_narrows_the_list(): void
    {
        $this->log($this->contest, 'info', 'Uma informacao');
        $this->log($this->contest, 'error', 'Uma falha');

        $response = $this->actingAs($this->createAdminUser())
            ->get('/backend/logs?contest_id='.$this->contest->id.'&type=error')
            ->assertOk();

        $response->assertSeeText('Uma falha');
        $response->assertDontSeeText('Uma informacao');
    }

    public function test_the_text_search_narrows_the_list(): void
    {
        $this->log($this->contest, 'info', 'Run #41 submitted');
        $this->log($this->contest, 'info', 'Clarification answered');

        $response = $this->actingAs($this->createAdminUser())
            ->get('/backend/logs?contest_id='.$this->contest->id.'&q=Clarification')
            ->assertOk();

        $response->assertSeeText('Clarification answered');
        $response->assertDontSeeText('Run #41 submitted');
    }

    public function test_the_date_range_narrows_the_list(): void
    {
        $old = $this->log($this->contest, 'info', 'Registro antigo');
        // created_at is not in ContestLog::$fillable, so ->update() would
        // silently drop it and the test would pass for the wrong reason.
        ContestLog::whereKey($old->id)->update(['created_at' => now()->subDays(10)]);
        $this->log($this->contest, 'info', 'Registro de hoje');

        $response = $this->actingAs($this->createAdminUser())
            ->get('/backend/logs?contest_id='.$this->contest->id.'&from='.now()->subDay()->toDateString())
            ->assertOk();

        $response->assertSeeText('Registro de hoje');
        $response->assertDontSeeText('Registro antigo');
    }

    public function test_the_practice_contest_is_not_offered_as_contest_history(): void
    {
        $practice = app(PracticeContest::class)->contest();
        $this->log($practice, 'info', 'Registro de treino');

        // Issue #43: practice is not an event, and its log is not the
        // contest history anyone audits here.
        $response = $this->actingAs($this->createAdminUser())
            ->get('/backend/logs?contest_id='.$practice->id)
            ->assertOk();

        $response->assertDontSeeText('Registro de treino');
    }

    public function test_the_listing_is_paginated_rather_than_unbounded(): void
    {
        // A five-hour contest with 200 teams produces a lot of rows.
        for ($i = 1; $i <= 60; $i++) {
            $this->log($this->contest, 'info', 'Registro numero '.$i);
        }

        $logs = $this->actingAs($this->createAdminUser())
            ->get('/backend/logs?contest_id='.$this->contest->id)
            ->assertOk()
            ->viewData('logs');

        $this->assertSame(50, $logs->perPage());
        $this->assertSame(60, $logs->total());
        $this->assertCount(50, $logs->items());
    }

    public function test_the_newest_entry_comes_first(): void
    {
        $this->log($this->contest, 'info', 'Primeiro');
        $this->log($this->contest, 'info', 'Ultimo');

        $logs = $this->actingAs($this->createAdminUser())
            ->get('/backend/logs?contest_id='.$this->contest->id)
            ->assertOk()
            ->viewData('logs');

        $this->assertSame('Ultimo', $logs->items()[0]->message);
    }

    public function test_the_site_of_an_entry_is_shown(): void
    {
        $site = Site::factory()->create(['contest_id' => $this->contest->id, 'name' => 'Sede Norte']);
        $this->log($this->contest, 'info', 'Algo aconteceu', ['site_id' => $site->id]);

        $this->actingAs($this->createAdminUser())
            ->get('/backend/logs?contest_id='.$this->contest->id)
            ->assertOk()
            ->assertSeeText('Sede Norte');
    }
}
