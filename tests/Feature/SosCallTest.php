<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Site;
use App\Models\SosCall;
use Helium\User;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/**
 * Issue #139 -- BOCA's S.O.S. (src/team/task.php).
 *
 * The thing worth testing here is not "a row is written"; it is who the row
 * reaches. A clarification goes to the jury and is about the problem; an
 * S.O.S. goes to the staff standing in that team's room and is about the
 * team. If the site scoping breaks, the feature silently becomes "shout at
 * every site at once", which is worse than not having it -- so the scoping
 * is asserted from both ends: the listing another site's staff get, and the
 * actions they can reach by guessing an id.
 */
class SosCallTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create([
            'start_time' => now()->subMinutes(30),
            'duration' => 300,
            'is_active' => true,
        ]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
    }

    private function user(string $type, ?Site $site = null): User
    {
        return User::factory()->create([
            'user_type' => $type,
            'contest_id' => $this->contest->id,
            'site_id' => ($site ?? $this->site)->id,
        ]);
    }

    private function team(?Site $site = null): User
    {
        return $this->user('team', $site);
    }

    private function staff(?Site $site = null): User
    {
        return $this->user('staff', $site);
    }

    private function raise(User $team, array $payload = [])
    {
        return $this->actingAs($team)->post('/sos', array_merge([
            'confirmation' => 'confirm',
        ], $payload));
    }

    // --- the team side ----------------------------------------------------

    public function test_a_team_raises_a_call_for_its_own_contest_and_site(): void
    {
        $team = $this->team();

        $this->raise($team, ['note' => 'teclado quebrado, baia 14'])
            ->assertRedirect(route('sos.create'))
            ->assertSessionHas('success');

        $call = SosCall::firstOrFail();
        $this->assertSame($this->contest->id, $call->contest_id);
        $this->assertSame($this->site->id, $call->site_id);
        $this->assertSame($team->user_id, $call->user_id);
        $this->assertSame(SosCall::STATUS_OPEN, $call->status);
        $this->assertSame('teclado quebrado, baia 14', $call->note);
        $this->assertSame(SosCall::ACTIVE_SLOT, $call->active_slot);
        $this->assertNull($call->acknowledged_at);
        $this->assertNull($call->resolved_at);
        // The contest has been running for 30 minutes.
        $this->assertGreaterThan(0, $call->contest_time);
    }

    public function test_the_call_reaches_the_contest_log_as_a_warning_without_the_teams_words(): void
    {
        // Issue #88's screen is where the organisation watches the event go
        // by, but the team's free text stays out of it: untrusted display
        // data has no business in the audit log.
        $this->raise($this->team(), ['note' => 'alguem passou mal']);

        $log = ContestLog::where('type', 'warning')->firstOrFail();
        $this->assertStringContainsString('S.O.S.', $log->message);
        $this->assertSame($this->site->id, $log->site_id);
        $this->assertSame(SosCall::first()->id, $log->context['sos_call_id']);
        $this->assertTrue($log->context['has_note']);
        $this->assertStringNotContainsString('alguem passou mal', json_encode($log->getAttributes()));
    }

    public function test_nothing_is_raised_without_the_confirmation(): void
    {
        // BOCA requires confirming, because a stray click sends staff
        // running across a gym.
        $this->actingAs($this->team())->post('/sos', ['note' => 'oops'])
            ->assertSessionHasErrors('confirmation');

        $this->assertSame(0, SosCall::count());
    }

    public function test_a_confirmation_with_the_wrong_value_is_refused(): void
    {
        $this->raise($this->team(), ['confirmation' => 'yes'])
            ->assertSessionHasErrors('confirmation');

        $this->assertSame(0, SosCall::count());
    }

    public function test_an_overlong_note_is_refused(): void
    {
        config(['sos.note_max_length' => 10]);

        $this->raise($this->team(), ['note' => str_repeat('a', 11)])
            ->assertSessionHasErrors('note');

        $this->assertSame(0, SosCall::count());
    }

    public function test_only_a_team_can_raise_one(): void
    {
        $this->post('/sos')->assertRedirect(route('login'));

        $this->actingAs($this->staff())->post('/sos', ['confirmation' => 'confirm'])->assertForbidden();
    }

    public function test_a_call_can_be_raised_before_the_contest_starts(): void
    {
        // Deliberately unlike the print queue (#94). A machine that will not
        // boot during setup is exactly when the staff are needed, and the
        // clock has not started yet.
        $this->contest->update(['start_time' => now()->addHour()]);

        $this->raise($this->team())->assertSessionHas('success');

        $this->assertSame(1, SosCall::count());
        $this->assertSame(0, SosCall::first()->contest_time);
    }

    // --- dedupe -----------------------------------------------------------

    public function test_pressing_the_button_again_does_not_bury_the_queue(): void
    {
        $team = $this->team();

        $this->raise($team, ['note' => 'primeiro'])->assertSessionHas('success');
        $this->raise($team, ['note' => 'segundo'])->assertSessionHas('info');
        $this->raise($team, ['note' => 'terceiro'])->assertSessionHas('info');

        // One row, and it is still the first one: a later press must not
        // overwrite what the team originally said.
        $this->assertSame(1, SosCall::count());
        $this->assertSame('primeiro', SosCall::first()->note);
    }

    public function test_the_dedupe_is_per_team_not_per_site(): void
    {
        // Two teams in the same room both having trouble is not spam.
        $this->raise($this->team())->assertSessionHas('success');
        $this->raise($this->team())->assertSessionHas('success');

        $this->assertSame(2, SosCall::count());
    }

    public function test_a_resolved_call_frees_the_team_to_call_again(): void
    {
        $team = $this->team();
        $this->raise($team)->assertSessionHas('success');

        $call = SosCall::firstOrFail();
        $this->actingAs($this->staff())->post(route('staff.sos.resolve', $call))->assertRedirect();

        $this->raise($team, ['note' => 'voltou a travar'])->assertSessionHas('success');

        $this->assertSame(2, SosCall::count());
    }

    public function test_the_database_refuses_a_second_open_call_even_without_the_controller(): void
    {
        // The controller's check is what produces a sentence instead of a
        // stack trace; this unique index is what makes the check hold when
        // two double-clicks race each other.
        $team = $this->team();
        $this->raise($team);

        $this->expectException(QueryException::class);

        SosCall::create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $team->user_id,
            'status' => SosCall::STATUS_OPEN,
            'contest_time' => 0,
            'active_slot' => SosCall::ACTIVE_SLOT,
        ]);
    }

    // --- the staff side ---------------------------------------------------

    public function test_staff_of_the_same_site_see_the_call(): void
    {
        $team = $this->team();
        $this->raise($team, ['note' => 'monitor apagou']);

        $this->actingAs($this->staff())->get(route('staff.sos'))
            ->assertOk()
            ->assertSeeText('monitor apagou')
            ->assertSeeText($team->fullname);
    }

    public function test_staff_of_another_site_do_not_see_the_call(): void
    {
        $otherSite = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->raise($this->team(), ['note' => 'monitor apagou']);

        // The staff who answer are the ones standing in that room.
        $this->actingAs($this->staff($otherSite))->get(route('staff.sos'))
            ->assertOk()
            ->assertDontSeeText('monitor apagou');
    }

    public function test_staff_of_another_site_cannot_acknowledge_or_resolve_it(): void
    {
        $otherSite = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->raise($this->team());
        $call = SosCall::firstOrFail();

        // The listing scoping is not a permission on its own -- the id is
        // guessable, and "handled by someone who is not in the building" is
        // the failure that matters.
        $intruder = $this->staff($otherSite);
        $this->actingAs($intruder)->post(route('staff.sos.acknowledge', $call))->assertForbidden();
        $this->actingAs($intruder)->post(route('staff.sos.resolve', $call))->assertForbidden();

        $this->assertSame(SosCall::STATUS_OPEN, $call->fresh()->status);
    }

    public function test_a_team_cannot_resolve_its_own_call(): void
    {
        $team = $this->team();
        $this->raise($team);
        $call = SosCall::firstOrFail();

        $this->actingAs($team)->get(route('staff.sos'))->assertForbidden();
        $this->actingAs($team)->post(route('staff.sos.resolve', $call))->assertForbidden();
        $this->actingAs($team)->post(route('staff.sos.acknowledge', $call))->assertForbidden();

        $this->assertSame(SosCall::STATUS_OPEN, $call->fresh()->status);
    }

    public function test_a_judge_does_not_get_the_queue(): void
    {
        // An S.O.S. is about the team and goes to the site; a clarification
        // is about the problem and goes to the jury. Keeping the jury out of
        // this queue is the distinction the issue is built on.
        $this->raise($this->team());

        $this->actingAs($this->user('judge'))->get(route('staff.sos'))->assertForbidden();
    }

    public function test_a_site_coordinator_of_the_same_site_can_work_the_queue(): void
    {
        $this->raise($this->team(), ['note' => 'sem energia na baia']);
        $call = SosCall::firstOrFail();

        $coordinator = $this->user('site');
        $this->actingAs($coordinator)->get(route('staff.sos'))->assertOk()->assertSeeText('sem energia na baia');
        $this->actingAs($coordinator)->post(route('staff.sos.resolve', $call))->assertRedirect();

        $this->assertTrue($call->fresh()->isResolved());
    }

    // --- state ------------------------------------------------------------

    public function test_open_becomes_acknowledged_becomes_resolved(): void
    {
        $this->raise($this->team());
        $call = SosCall::firstOrFail();
        $staff = $this->staff();

        $this->actingAs($staff)->post(route('staff.sos.acknowledge', $call))->assertSessionHas('success');
        $call->refresh();
        $this->assertTrue($call->isAcknowledged());
        $this->assertSame($staff->user_id, $call->acknowledged_by);
        $this->assertNotNull($call->acknowledged_at);
        // Still unresolved, so the team still holds the slot.
        $this->assertSame(SosCall::ACTIVE_SLOT, $call->active_slot);

        $this->actingAs($staff)->post(route('staff.sos.resolve', $call))->assertSessionHas('success');
        $call->refresh();
        $this->assertTrue($call->isResolved());
        $this->assertSame($staff->user_id, $call->resolved_by);
        $this->assertNotNull($call->resolved_at);
        $this->assertNull($call->active_slot);
    }

    public function test_an_open_call_can_be_resolved_without_being_acknowledged_first(): void
    {
        // The common case: read the row, walk over, swap the keyboard.
        $this->raise($this->team());
        $call = SosCall::firstOrFail();

        $this->actingAs($this->staff())->post(route('staff.sos.resolve', $call))->assertSessionHas('success');

        $call->refresh();
        $this->assertTrue($call->isResolved());
        $this->assertNull($call->acknowledged_at);
    }

    public function test_a_resolved_call_cannot_be_acknowledged_or_resolved_again(): void
    {
        $this->raise($this->team());
        $call = SosCall::firstOrFail();

        $first = $this->staff();
        $this->actingAs($first)->post(route('staff.sos.resolve', $call));

        $second = $this->staff();
        $this->actingAs($second)->post(route('staff.sos.resolve', $call))->assertSessionHas('error');
        $this->actingAs($second)->post(route('staff.sos.acknowledge', $call))->assertSessionHas('error');

        // The second staff member racing the same row must not rewrite who
        // actually dealt with it.
        $call->refresh();
        $this->assertTrue($call->isResolved());
        $this->assertSame($first->user_id, $call->resolved_by);
        $this->assertNull($call->acknowledged_at);
    }

    public function test_acknowledging_twice_keeps_the_first_responder(): void
    {
        $this->raise($this->team());
        $call = SosCall::firstOrFail();

        $first = $this->staff();
        $this->actingAs($first)->post(route('staff.sos.acknowledge', $call));

        $this->actingAs($this->staff())->post(route('staff.sos.acknowledge', $call))->assertSessionHas('error');

        $this->assertSame($first->user_id, $call->fresh()->acknowledged_by);
    }

    public function test_the_team_sees_the_state_of_its_own_call(): void
    {
        $team = $this->team();
        $this->raise($team, ['note' => 'maquina nao liga']);

        $this->actingAs($team)->get('/sos')->assertOk()
            ->assertSeeText('maquina nao liga')
            ->assertSeeText('Aberto')
            ->assertSeeText('Você já tem um chamado aberto.');
    }

    public function test_a_team_does_not_see_another_teams_calls(): void
    {
        $mine = $this->team();
        $theirs = $this->team();

        $this->raise($mine, ['note' => 'meu chamado']);
        $this->raise($theirs, ['note' => 'chamado deles']);

        $this->actingAs($mine)->get('/sos')->assertOk()
            ->assertSeeText('meu chamado')
            ->assertDontSeeText('chamado deles');
    }
}
