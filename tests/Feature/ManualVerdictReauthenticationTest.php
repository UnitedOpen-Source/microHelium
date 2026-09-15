<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use Helium\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #201 -- setting a verdict by hand is the most consequential single
 * action in the system. It moves the standings, and there is no second
 * instance.
 *
 * The CCS requirements: "The CCS must require a separate authentication
 * every time a judgment is changed manually and all such changes must be
 * logged." Neither half existed: any open session could set a verdict with
 * one click, and the change was recorded the way every other action is.
 *
 * The password is asked for per action rather than through Laravel's
 * `password.confirm`, which remembers for three hours. What this defends
 * against is not a remote attacker -- it is the jury workstation left open
 * in a room people walk through, and a three-hour window is precisely the
 * window that does not help.
 */
class ManualVerdictReauthenticationTest extends TestCase
{
    private Contest $contest;

    private Run $run;

    private Answer $accepted;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()->subHour()]);
        $problem = Problem::factory()->create(['contest_id' => $this->contest->id]);
        $language = Language::factory()->create(['contest_id' => $this->contest->id]);
        $this->accepted = Answer::factory()->create([
            'contest_id' => $this->contest->id,
            'short_name' => 'AC',
            'is_accepted' => true,
        ]);

        $this->run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'user_id' => $this->createTestUser(['contest_id' => $this->contest->id])->user_id,
            'status' => 'pending',
            'answer_id' => null,
        ]);
    }

    private function judge(): User
    {
        return $this->createTestUser(['user_type' => 'judge', 'contest_id' => $this->contest->id]);
    }

    public function test_the_web_door_refuses_a_verdict_without_the_password(): void
    {
        $this->actingAs($this->judge())
            ->post("/judge/runs/{$this->run->id}", ['answer_id' => $this->accepted->id])
            ->assertStatus(422);

        $this->assertSame('pending', $this->run->fresh()->status, 'a verdict was written without a password');
    }

    public function test_the_web_door_refuses_a_wrong_password(): void
    {
        $this->actingAs($this->judge())
            ->post("/judge/runs/{$this->run->id}", [
                'answer_id' => $this->accepted->id,
                'password' => 'nao-e-essa',
            ])
            ->assertStatus(422);

        $this->assertNull($this->run->fresh()->answer_id);
    }

    public function test_the_right_password_lets_the_verdict_through(): void
    {
        $this->actingAs($this->judge())
            ->post("/judge/runs/{$this->run->id}", [
                'answer_id' => $this->accepted->id,
                'password' => 'password',
            ])
            ->assertRedirect(route('judge.runs'));

        $this->assertSame($this->accepted->id, $this->run->fresh()->answer_id);
    }

    /**
     * A token answers "who you were when it was issued". This asks who you
     * are now, which is the same question the web door asks.
     */
    public function test_the_api_door_asks_too(): void
    {
        Sanctum::actingAs($this->judge());

        $this->putJson("/api/runs/{$this->run->id}/judge", ['answer_id' => $this->accepted->id])
            ->assertStatus(422);

        $this->assertSame('pending', $this->run->fresh()->status);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->judge());

        // A different judge with the same password still cannot write this
        // verdict without presenting it -- the check is against the acting
        // user, not against "some valid password".
        $this->putJson("/api/runs/{$this->run->id}/judge", [
            'answer_id' => $this->accepted->id,
            'password' => 'password',
        ])->assertStatus(200);

        $this->assertSame($this->accepted->id, $this->run->fresh()->answer_id);
    }

    /**
     * The other half of the requirement: the change gets its own record,
     * with the before and the after. "Who changed what, from what, to what"
     * is the question asked after a contest, and answering it by grepping a
     * stream of every action is not answering it.
     */
    public function test_the_change_is_recorded_with_the_before_and_the_after(): void
    {
        $judge = $this->judge();

        $this->actingAs($judge)->post("/judge/runs/{$this->run->id}", [
            'answer_id' => $this->accepted->id,
            'password' => 'password',
            'reason' => 'Saida com espaco extra aceita pela banca',
        ]);

        $entry = ContestLog::where('contest_id', $this->contest->id)
            ->get()
            ->first(fn (ContestLog $log) => ($log->context['event'] ?? null) === 'manual_verdict');

        $this->assertNotNull($entry, 'the manual verdict left no dedicated record');
        $this->assertSame($this->run->id, $entry->context['run_id']);
        $this->assertNull($entry->context['from'], 'the run had no verdict before');
        $this->assertSame('AC', $entry->context['to']);
        $this->assertSame($judge->user_id, $entry->context['user_id']);
        $this->assertSame('Saida com espaco extra aceita pela banca', $entry->context['reason']);
    }

    /**
     * Verifying is approving what the machine already said (#138); this
     * gate is for overriding it. Asking for a password to confirm a verdict
     * the judge did not choose would be friction with nothing behind it.
     */
    public function test_verifying_does_not_ask_for_a_password(): void
    {
        $this->contest->update(['verification_required' => true]);
        $this->run->update(['status' => 'judged', 'answer_id' => $this->accepted->id]);

        $this->actingAs($this->judge())
            ->post(route('judge.runs.verify', $this->run))
            ->assertRedirect();

        $this->assertNotNull($this->run->fresh()->verified_at);
    }
}
