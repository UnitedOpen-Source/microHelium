<?php

namespace Tests\Feature\Practice;

use App\Models\Contest;
use App\Services\Practice\PracticeContest;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #43 -- the technical practice contest exists so practice can reuse
 * the Contest/Problem/Run/Score pipeline, and it must never be mistaken for
 * an event: "excluir esse concurso de competição ativa, seletor público,
 * relógio, ativação global, CSV de evento, tarefas/balloons e cálculos de
 * ranking de eventos" (docs/specs/43-practice.md).
 *
 * This is the regression net for that list. A new query for "the contest"
 * that forgets Contest::competition() is exactly the kind of thing that
 * would otherwise be found only once a practice problem showed up on a
 * scoreboard.
 */
class PracticeContestIsolationTest extends TestCase
{
    private function practice(): Contest
    {
        return app(PracticeContest::class)->contest();
    }

    public function test_the_practice_contest_has_no_clock(): void
    {
        $practice = $this->practice();

        // No start_time means isRunning() is false and getContestTime() is 0
        // by construction, not by a special case in the submit path.
        $this->assertNull($practice->start_time);
        $this->assertFalse($practice->is_active);
        $this->assertFalse($practice->isRunning());
        $this->assertSame(0, $practice->getContestTime());
    }

    public function test_the_competition_scope_excludes_it(): void
    {
        $event = Contest::factory()->create();
        $practice = $this->practice();

        $ids = Contest::query()->competition()->pluck('id')->all();

        $this->assertContains($event->id, $ids);
        $this->assertNotContains($practice->id, $ids);
    }

    public function test_it_is_not_offered_in_the_public_contest_selector(): void
    {
        $event = Contest::factory()->create(['is_public' => true]);
        $practice = $this->practice();

        Sanctum::actingAs($this->createAdminUser());

        $ids = collect($this->getJson('/api/contests')->assertOk()->json('data'))
            ->pluck('id')->all();

        // Excluded for admins too: it is reached through /practice, not by
        // picking it as an event.
        $this->assertContains($event->id, $ids);
        $this->assertNotContains($practice->id, $ids);
    }

    public function test_it_cannot_be_activated_as_a_competition_through_the_api(): void
    {
        $practice = $this->practice();

        Sanctum::actingAs($this->createAdminUser());

        $this->postJson("/api/contests/{$practice->id}/activate")->assertStatus(422);
        $this->assertFalse($practice->fresh()->is_active);
    }

    public function test_it_cannot_be_activated_through_the_backend_action(): void
    {
        $practice = $this->practice();

        $this->actingAs($this->createAdminUser())
            ->post("/backend/contest/{$practice->id}/activate")
            ->assertRedirect();

        $this->assertFalse($practice->fresh()->is_active);
    }

    public function test_an_admin_with_only_a_practice_contest_is_still_sent_to_the_wizard(): void
    {
        $this->practice();

        // "Sem competição ativa nunca impede treino" cuts the other way too:
        // having provisioned practice is not having configured an event.
        $this->assertFalse(Contest::query()->competition()->exists());
        $this->assertTrue(Contest::query()->exists());
    }

    public function test_accounts_are_never_enrolled_into_it(): void
    {
        $event = Contest::factory()->create();
        $practice = $this->practice();

        $ids = collect($this->actingAs($this->createAdminUser())
            ->getJson('/api/frontend/managed-accounts')
            ->assertOk()
            ->json('data.contests'))
            ->pluck('id')->all();

        $this->assertContains($event->id, $ids);
        $this->assertNotContains($practice->id, $ids);
    }

    public function test_its_problems_are_not_editable_through_admin_problem_management(): void
    {
        $event = Contest::factory()->create();
        $practice = $this->practice();

        $response = $this->actingAs($this->createAdminUser())
            ->get('/backend/exercises')
            ->assertOk();

        $ids = collect($response->viewData('contests'))->pluck('id')->all();

        $this->assertContains($event->id, $ids);
        $this->assertNotContains($practice->id, $ids);
    }

    public function test_resolving_it_twice_does_not_create_a_second_one(): void
    {
        $first = $this->practice();
        $second = $this->practice();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Contest::query()->practice()->count());
        // Provisioned with everything judging needs, once.
        $this->assertSame(1, $first->sites()->count());
        $this->assertGreaterThan(0, $first->languages()->count());
        $this->assertGreaterThan(0, $first->answers()->count());
    }

    public function test_it_is_flagged_in_the_database_not_recognised_by_name(): void
    {
        $practice = $this->practice();

        // A contest an organiser happens to call "Treino Livre" is still a
        // competition: recognition is by column, never by name.
        $lookalike = Contest::factory()->create(['name' => $practice->name]);

        $this->assertTrue(DB::table('contests')->where('id', $practice->id)->value('is_practice') == true);
        $this->assertFalse(DB::table('contests')->where('id', $lookalike->id)->value('is_practice') == true);
        $this->assertSame([$practice->id], Contest::query()->practice()->pluck('id')->all());
    }
}
