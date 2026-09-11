<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\IdempotencyKey;
use App\Models\WebcastCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebcastControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_non_admin_cannot_reach_any_webcast_endpoint(): void
    {
        $this->getJson('/api/frontend/webcast')->assertStatus(401);

        $team = $this->createTestUser(['user_type' => 'team']);
        $this->actingAs($team);
        $this->getJson('/api/frontend/webcast')->assertStatus(403);
        $this->postJson('/api/frontend/webcast/credentials', [])->assertStatus(403);
        $this->deleteJson('/api/frontend/webcast/credentials/1')->assertStatus(403);
    }

    public function test_index_with_no_contest_selected_returns_null_contest_and_empty_items(): void
    {
        $this->actingAs($this->createAdminUser());
        Contest::factory()->create(['name' => 'Maratona A']);

        $response = $this->getJson('/api/frontend/webcast')->assertOk();
        $data = $response->json('data');

        $this->assertNull($data['contest']);
        $this->assertSame([], $data['items']);
        $this->assertFalse($data['capabilities']['can_export']);
        $this->assertFalse($data['capabilities']['can_manage_credentials']);
        $this->assertNull($data['export_url']);
        $this->assertCount(1, $data['contests']);
    }

    public function test_index_with_invalid_contest_id_behaves_like_no_selection(): void
    {
        $this->actingAs($this->createAdminUser());

        $response = $this->getJson('/api/frontend/webcast?contest_id=999999')->assertOk();

        $this->assertNull($response->json('data.contest'));
        $this->assertSame([], $response->json('data.items'));
    }

    public function test_index_with_valid_contest_lists_credential_metadata_without_secrets(): void
    {
        config(['webcast.export_enabled' => false]);
        $admin = $this->createAdminUser();
        $this->actingAs($admin);
        $contest = Contest::factory()->create();

        [$active] = WebcastCredential::issue($contest, 'Ativa', now()->addDay(), $admin->user_id);
        [$expired] = WebcastCredential::issue($contest, 'Expirada', now()->addMinute(), $admin->user_id);
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        [$revoked] = WebcastCredential::issue($contest, 'Revogada', now()->addDay(), $admin->user_id);
        $revoked->revoke();

        $response = $this->getJson("/api/frontend/webcast?contest_id={$contest->id}")->assertOk();
        $data = $response->json('data');

        $this->assertSame($contest->id, $data['contest']['id']);
        $this->assertTrue($data['capabilities']['can_manage_credentials']);
        $this->assertFalse($data['capabilities']['can_export']);
        $this->assertNotNull($data['export_url']);
        $this->assertCount(3, $data['items']);

        foreach ($data['items'] as $item) {
            $this->assertArrayNotHasKey('secret', $item);
            $this->assertArrayNotHasKey('token_hash', $item);
        }

        $statuses = collect($data['items'])->pluck('status', 'label');
        $this->assertSame('active', $statuses['Ativa']);
        $this->assertSame('expired', $statuses['Expirada']);
        $this->assertSame('revoked', $statuses['Revogada']);
    }

    public function test_index_paginates_20_per_page_with_stable_ordering(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin);
        $contest = Contest::factory()->create();

        for ($i = 0; $i < 25; $i++) {
            WebcastCredential::issue($contest, "Cred {$i}", now()->addDay(), $admin->user_id);
        }

        $page1 = $this->getJson("/api/frontend/webcast?contest_id={$contest->id}&page=1")->assertOk();
        $this->assertCount(20, $page1->json('data.items'));
        $this->assertSame(1, $page1->json('data.meta.current_page'));
        $this->assertSame(2, $page1->json('data.meta.last_page'));
        $this->assertSame(25, $page1->json('data.meta.total'));

        $page2 = $this->getJson("/api/frontend/webcast?contest_id={$contest->id}&page=2")->assertOk();
        $this->assertCount(5, $page2->json('data.items'));
    }

    public function test_create_credential_returns_secret_once_and_validates_fields(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin);
        $contest = Contest::factory()->create();

        $response = $this->postJson('/api/frontend/webcast/credentials', [
            'contest_id' => $contest->id,
            'label' => 'Computador da cerimonia',
            'expires_at' => now()->addHour()->toISOString(),
        ])->assertStatus(201);

        $id = $response->json('data.id');
        $secret = $response->json('data.secret');
        $this->assertNotEmpty($secret);
        $this->assertDatabaseHas('webcast_credentials', ['id' => $id, 'contest_id' => $contest->id, 'label' => 'Computador da cerimonia']);
        $this->assertDatabaseMissing('webcast_credentials', ['token_hash' => $secret]);

        $stored = WebcastCredential::find($id);
        $this->assertSame(hash('sha256', $secret), $stored->token_hash);

        // The list endpoint never leaks the secret afterwards.
        $listed = $this->getJson("/api/frontend/webcast?contest_id={$contest->id}")->assertOk();
        $this->assertArrayNotHasKey('secret', $listed->json('data.items.0'));
    }

    public function test_create_credential_validation_errors(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin);
        $contest = Contest::factory()->create();

        $this->postJson('/api/frontend/webcast/credentials', [
            'contest_id' => $contest->id,
            'label' => '',
            'expires_at' => now()->addHour()->toISOString(),
        ])->assertStatus(422)->assertJsonValidationErrors('label');

        $this->postJson('/api/frontend/webcast/credentials', [
            'contest_id' => $contest->id,
            'label' => str_repeat('a', 81),
            'expires_at' => now()->addHour()->toISOString(),
        ])->assertStatus(422)->assertJsonValidationErrors('label');

        $this->postJson('/api/frontend/webcast/credentials', [
            'contest_id' => $contest->id,
            'label' => 'Valido',
            'expires_at' => now()->subHour()->toISOString(),
        ])->assertStatus(422)->assertJsonValidationErrors('expires_at');

        $this->postJson('/api/frontend/webcast/credentials', [
            'contest_id' => $contest->id,
            'label' => 'Valido',
            'expires_at' => now()->addYears(5)->toISOString(),
        ])->assertStatus(422)->assertJsonValidationErrors('expires_at');

        $this->postJson('/api/frontend/webcast/credentials', [
            'contest_id' => 999999,
            'label' => 'Valido',
            'expires_at' => now()->addHour()->toISOString(),
        ])->assertStatus(422)->assertJsonValidationErrors('contest_id');
    }

    public function test_idempotency_key_replay_with_same_payload_never_repeats_the_secret(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin);
        $contest = Contest::factory()->create();

        $payload = [
            'contest_id' => $contest->id,
            'label' => 'Idempotente',
            'expires_at' => now()->addHour()->toISOString(),
        ];

        $first = $this->postJson('/api/frontend/webcast/credentials', $payload, ['Idempotency-Key' => 'k-1'])
            ->assertStatus(201);
        $firstSecret = $first->json('data.secret');
        $this->assertNotEmpty($firstSecret);

        $replay = $this->postJson('/api/frontend/webcast/credentials', $payload, ['Idempotency-Key' => 'k-1'])
            ->assertStatus(201);
        $this->assertNull($replay->json('data.secret'));
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));

        // Only one credential was actually created.
        $this->assertSame(1, WebcastCredential::where('contest_id', $contest->id)->count());
    }

    public function test_idempotency_key_reused_with_different_payload_is_a_conflict(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin);
        $contest = Contest::factory()->create();

        $this->postJson('/api/frontend/webcast/credentials', [
            'contest_id' => $contest->id,
            'label' => 'Primeiro',
            'expires_at' => now()->addHour()->toISOString(),
        ], ['Idempotency-Key' => 'k-2'])->assertStatus(201);

        $this->postJson('/api/frontend/webcast/credentials', [
            'contest_id' => $contest->id,
            'label' => 'Outro nome',
            'expires_at' => now()->addHour()->toISOString(),
        ], ['Idempotency-Key' => 'k-2'])->assertStatus(409);

        $this->assertSame(1, WebcastCredential::where('contest_id', $contest->id)->count());
    }

    public function test_validation_failure_does_not_burn_the_idempotency_key(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin);
        $contest = Contest::factory()->create();

        $this->postJson('/api/frontend/webcast/credentials', [
            'contest_id' => $contest->id,
            'label' => '',
            'expires_at' => now()->addHour()->toISOString(),
        ], ['Idempotency-Key' => 'k-3'])->assertStatus(422);

        // Same key, now with a valid payload, succeeds -- the failed
        // attempt did not permanently consume the key.
        $this->postJson('/api/frontend/webcast/credentials', [
            'contest_id' => $contest->id,
            'label' => 'Agora valido',
            'expires_at' => now()->addHour()->toISOString(),
        ], ['Idempotency-Key' => 'k-3'])->assertStatus(201);
    }

    public function test_concurrent_create_race_on_same_key_falls_back_to_replay_instead_of_crashing(): void
    {
        // Simulates two requests both reaching IdempotencyGuard::handle()'s
        // "does a row exist?" check before either has inserted: the second
        // one's IdempotencyKey::create() call collides with the unique
        // index (idempotency_actor_route_key_unique) instead of finding an
        // existing row up front. That must fall back to a normal replay,
        // not bubble up as an unhandled QueryException/500.
        $admin = $this->createAdminUser();
        $this->actingAs($admin);
        $contest = Contest::factory()->create();
        $key = 'race-key';

        $winnerInserted = false;
        IdempotencyKey::creating(function ($model) use (&$winnerInserted) {
            if ($winnerInserted) {
                return;
            }
            $winnerInserted = true;

            DB::table('idempotency_keys')->insert([
                'actor' => $model->actor,
                'route' => $model->route,
                'idempotency_key' => $model->idempotency_key,
                'payload_hash' => $model->payload_hash,
                'status' => 'completed',
                'response_status' => 201,
                'response_body' => Crypt::encryptString(json_encode(['data' => ['id' => 999999, 'secret' => null]])),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $response = $this->postJson('/api/frontend/webcast/credentials', [
                'contest_id' => $contest->id,
                'label' => 'Corrida',
                'expires_at' => now()->addHour()->toISOString(),
            ], ['Idempotency-Key' => $key]);
        } finally {
            IdempotencyKey::flushEventListeners();
        }

        $response->assertStatus(201);
        $this->assertSame(999999, $response->json('data.id'));
        $this->assertNull($response->json('data.secret'));
        // The request that lost the race never actually created its own
        // credential row.
        $this->assertSame(0, WebcastCredential::where('contest_id', $contest->id)->count());
    }

    public function test_revoke_is_idempotent_and_hides_the_credential_going_forward(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin);
        $contest = Contest::factory()->create();
        [$credential] = WebcastCredential::issue($contest, 'A revogar', now()->addDay(), $admin->user_id);

        $this->deleteJson("/api/frontend/webcast/credentials/{$credential->id}")
            ->assertStatus(200)
            ->assertJson(['data' => ['id' => $credential->id, 'revoked' => true]]);

        $this->assertSame('revoked', $credential->fresh()->status());

        // Repeating the revoke is safe.
        $this->deleteJson("/api/frontend/webcast/credentials/{$credential->id}")
            ->assertStatus(200)
            ->assertJson(['data' => ['id' => $credential->id, 'revoked' => true]]);
    }

    public function test_revoke_of_nonexistent_credential_is_also_idempotent_success(): void
    {
        $this->actingAs($this->createAdminUser());

        $this->deleteJson('/api/frontend/webcast/credentials/999999')
            ->assertStatus(200)
            ->assertJson(['data' => ['id' => 999999, 'revoked' => true]]);
    }
}
