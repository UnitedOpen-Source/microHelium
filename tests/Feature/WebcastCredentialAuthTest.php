<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Site;
use App\Models\WebcastCredential;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #44's acceptance criteria: a webcast credential reads its own
 * contest's unfrozen scoreboard and NOTHING else -- no submission, no
 * problem/test listing, no judging, no user management, no other
 * contest's data. Expired/revoked/nonexistent credentials must all be
 * denied identically.
 */
class WebcastCredentialAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credential_reads_its_own_unfrozen_scoreboard(): void
    {
        $contest = Contest::factory()->create(['freeze_time' => 0]);
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $team = User::factory()->create(['contest_id' => $contest->id, 'site_id' => $site->id, 'user_type' => 'team', 'fullname' => 'Equipe X']);
        [$credential, $secret] = WebcastCredential::issue($contest, 'Consumidor', now()->addDay(), null);

        $response = $this->withHeaders(['Authorization' => "Bearer {$secret}"])
            ->getJson('/api/webcast/scoreboard');

        $response->assertOk();
        $this->assertSame($contest->id, $response->json('contest.id'));
        $this->assertArrayHasKey('scoreboard', $response->json());

        $credential->refresh();
        $this->assertNotNull($credential->last_used_at);
    }

    public function test_missing_expired_revoked_and_nonexistent_tokens_are_denied_identically(): void
    {
        $contest = Contest::factory()->create();
        [$active, $activeSecret] = WebcastCredential::issue($contest, 'Ativa', now()->addDay(), null);
        [$expired, $expiredSecret] = WebcastCredential::issue($contest, 'Expira', now()->addMinute(), null);
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        [$revoked, $revokedSecret] = WebcastCredential::issue($contest, 'Revoga', now()->addDay(), null);
        $revoked->revoke();

        $noToken = $this->getJson('/api/webcast/scoreboard');
        $badToken = $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])->getJson('/api/webcast/scoreboard');
        $expiredResponse = $this->withHeaders(['Authorization' => "Bearer {$expiredSecret}"])->getJson('/api/webcast/scoreboard');
        $revokedResponse = $this->withHeaders(['Authorization' => "Bearer {$revokedSecret}"])->getJson('/api/webcast/scoreboard');

        foreach ([$noToken, $badToken, $expiredResponse, $revokedResponse] as $response) {
            $response->assertStatus(401);
        }

        $messages = collect([$noToken, $badToken, $expiredResponse, $revokedResponse])->map(fn ($r) => $r->json('message'));
        $this->assertCount(1, $messages->unique(), 'All denial reasons must be indistinguishable: '.$messages->implode(' | '));

        // A genuinely valid token still works, proving the shared message
        // above isn't just a global "everything is broken" fallback.
        $this->withHeaders(['Authorization' => "Bearer {$activeSecret}"])
            ->getJson('/api/webcast/scoreboard')
            ->assertOk();
    }

    public function test_credential_cannot_read_another_contests_scoreboard_only_its_own(): void
    {
        $contestA = Contest::factory()->create();
        $contestB = Contest::factory()->create();
        Site::factory()->create(['contest_id' => $contestA->id]);
        Site::factory()->create(['contest_id' => $contestB->id]);
        [, $secretA] = WebcastCredential::issue($contestA, 'A', now()->addDay(), null);

        $response = $this->withHeaders(['Authorization' => "Bearer {$secretA}"])->getJson('/api/webcast/scoreboard');

        $response->assertOk();
        $this->assertSame($contestA->id, $response->json('contest.id'));
        $this->assertNotSame($contestB->id, $response->json('contest.id'));
    }

    public function test_credential_cannot_submit_runs(): void
    {
        $contest = Contest::factory()->create();
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        [, $secret] = WebcastCredential::issue($contest, 'C', now()->addDay(), null);

        $response = $this->withHeaders(['Authorization' => "Bearer {$secret}"])
            ->postJson("/submit/{$problem->id}", ['language_id' => 1]);

        $this->assertContains($response->status(), [401, 403, 419]);
    }

    public function test_credential_cannot_list_or_manage_problems(): void
    {
        $contest = Contest::factory()->create();
        [, $secret] = WebcastCredential::issue($contest, 'C', now()->addDay(), null);

        // /exercises and /exercise/{id} are intentionally public practice
        // pages in this app (no auth at all) -- unrelated to this
        // credential. What must stay closed is the admin problem
        // management screen.
        //
        // Not exercised here: /api/problems (auth:sanctum). Sending any
        // Authorization: Bearer header to a sanctum-guarded route makes
        // Laravel\Sanctum\Guard query the personal_access_tokens table --
        // which this app has never migrated (no user has ever issued a
        // Sanctum token), so that query 500s instead of 401ing. That's a
        // pre-existing gap unrelated to issue #44 (the webcast credential
        // never touches the Sanctum guard at all); flagged here rather
        // than silently worked around, and left unfixed as out of scope.
        $webResponse = $this->withHeaders(['Authorization' => "Bearer {$secret}"])->getJson('/backend/exercises');
        $this->assertContains($webResponse->status(), [401, 403]);
    }

    public function test_credential_cannot_judge_runs(): void
    {
        $contest = Contest::factory()->create();
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $team = User::factory()->create(['contest_id' => $contest->id, 'site_id' => $site->id, 'user_type' => 'team']);
        $language = Language::factory()->create(['contest_id' => $contest->id]);
        $run = Run::factory()->create([
            'contest_id' => $contest->id, 'site_id' => $site->id, 'user_id' => $team->user_id,
            'problem_id' => $problem->id, 'language_id' => $language->id, 'status' => 'pending',
        ]);
        [, $secret] = WebcastCredential::issue($contest, 'C', now()->addDay(), null);

        $webResponse = $this->withHeaders(['Authorization' => "Bearer {$secret}"])
            ->postJson("/judge/runs/{$run->id}", ['answer_id' => 1]);
        $this->assertContains($webResponse->status(), [401, 403, 419]);

        // Not exercised here: PUT /api/runs/{run}/judge (auth:sanctum) --
        // see the comment in test_credential_cannot_list_or_manage_
        // problems() about the pre-existing, unrelated missing
        // personal_access_tokens migration.
    }

    public function test_credential_cannot_manage_users(): void
    {
        $contest = Contest::factory()->create();
        [, $secret] = WebcastCredential::issue($contest, 'C', now()->addDay(), null);

        $webResponse = $this->withHeaders(['Authorization' => "Bearer {$secret}"])->getJson('/backend/users');
        $this->assertContains($webResponse->status(), [401, 403]);

        $webPost = $this->withHeaders(['Authorization' => "Bearer {$secret}"])
            ->postJson('/backend/users', ['username' => 'x']);
        $this->assertContains($webPost->status(), [401, 403, 419]);
    }

    public function test_credential_cannot_reach_its_own_admin_issuance_endpoints(): void
    {
        $contest = Contest::factory()->create();
        [, $secret] = WebcastCredential::issue($contest, 'C', now()->addDay(), null);

        $this->withHeaders(['Authorization' => "Bearer {$secret}"])
            ->getJson('/api/frontend/webcast')
            ->assertStatus(401);
    }

    public function test_scoreboard_reads_are_rate_limited(): void
    {
        $contest = Contest::factory()->create();
        [, $secret] = WebcastCredential::issue($contest, 'C', now()->addDay(), null);

        $last = null;
        for ($i = 0; $i < 21; $i++) {
            $last = $this->withHeaders(['Authorization' => "Bearer {$secret}"])->getJson('/api/webcast/scoreboard');
        }

        $this->assertSame(429, $last->status());
    }
}
