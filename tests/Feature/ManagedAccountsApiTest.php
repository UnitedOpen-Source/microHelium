<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Helium\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #47 -- real backend for GET/POST /api/frontend/managed-accounts.
 * Covers the spec's "Critérios de aceite" 1, 2, 3, 4 and the idempotency
 * requirement.
 */
class ManagedAccountsApiTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function contestWithSite(): array
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create(['contest_id' => $contest->id]);

        return [$contest, $site];
    }

    // --- Criterio 3: acesso administrativo -----------------------------

    public function test_admin_can_list_managed_accounts(): void
    {
        [$contest, $site] = $this->contestWithSite();
        $admin = $this->createAdminUser();
        User::factory()->managed(null, $admin->user_id)->create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ]);

        $this->actingAs($admin);
        $response = $this->getJson('/api/frontend/managed-accounts')->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'capabilities' => ['can_create'],
                'contests' => [['id', 'name', 'sites' => [['id', 'name']]]],
                'items' => [['id', 'fullname', 'username', 'contest_name', 'site_name', 'privacy_locked', 'visibility', 'external_linking_allowed', 'privacy_reason']],
                'meta' => ['current_page', 'last_page', 'total'],
            ],
        ]);
        $response->assertJsonPath('data.capabilities.can_create', true);
    }

    public function test_participant_judge_and_site_get_forbidden_on_listing(): void
    {
        foreach (['team', 'judge', 'site'] as $userType) {
            $user = $this->createTestUser(['user_type' => $userType]);
            $this->actingAs($user);
            $this->getJson('/api/frontend/managed-accounts')->assertForbidden();
        }
    }

    public function test_guest_gets_401_not_a_redirect(): void
    {
        $response = $this->getJson('/api/frontend/managed-accounts');
        $response->assertUnauthorized();
    }

    public function test_listing_never_exposes_birthdate_email_password_or_token(): void
    {
        [$contest, $site] = $this->contestWithSite();
        $admin = $this->createAdminUser();
        User::factory()->managed('2010-05-05', $admin->user_id)->create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'fullname' => 'Participante Sigiloso',
        ]);

        $this->actingAs($admin);
        $response = $this->getJson('/api/frontend/managed-accounts')->assertOk();

        $raw = $response->getContent();
        $this->assertStringNotContainsString('birthdate', $raw);
        $this->assertStringNotContainsString('2010-05-05', $raw);
        $this->assertStringNotContainsString('password', $raw);
        $this->assertStringNotContainsString('token', $raw);
        $this->assertStringNotContainsString('"email"', $raw);
    }

    // --- Criterio 1: privado desde a criacao, sem contornar via visibility manipulado

    public function test_created_account_starts_private_locked_and_ignores_manipulated_visibility_and_user_type(): void
    {
        [$contest, $site] = $this->contestWithSite();
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $response = $this->postJson('/api/frontend/managed-accounts', [
            'fullname' => 'Participante Novo',
            'username' => 'participante-novo',
            'birthdate' => null,
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            // manipulated/unsupported fields -- must be ignored entirely
            'visibility' => 'public',
            'user_type' => 'admin',
            'profile_visibility' => 'public',
        ], ['Idempotency-Key' => 'key-1'])->assertCreated();

        $response->assertJsonStructure(['data' => ['id', 'activation_url']]);
        $userId = $response->json('data.id');

        $user = User::findOrFail($userId);
        $this->assertSame('private', $user->profile_visibility);
        $this->assertSame(User::TYPE_TEAM, $user->user_type);
        $this->assertFalse($user->is_enabled);
        $this->assertTrue($user->privacyLocked());
        $this->assertFalse($user->externalLinkingAllowed());
    }

    public function test_create_requires_admin(): void
    {
        [$contest, $site] = $this->contestWithSite();
        $this->actingAs($this->createTestUser(['user_type' => 'team']));

        $this->postJson('/api/frontend/managed-accounts', [
            'fullname' => 'X',
            'username' => 'x-user',
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ], ['Idempotency-Key' => 'key-x'])->assertForbidden();
    }

    // --- Criterio 4: site de outro contest = 422; username duplicado = 422

    public function test_site_from_a_different_contest_is_422(): void
    {
        [$contest] = $this->contestWithSite();
        [$otherContest, $otherSite] = $this->contestWithSite();
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $response = $this->postJson('/api/frontend/managed-accounts', [
            'fullname' => 'Participante',
            'username' => 'participante-x',
            'contest_id' => $contest->id,
            'site_id' => $otherSite->id,
        ], ['Idempotency-Key' => 'key-2']);

        $response->assertStatus(422)->assertJsonValidationErrors(['site_id']);
    }

    public function test_duplicate_username_is_422_case_insensitive(): void
    {
        [$contest, $site] = $this->contestWithSite();
        $admin = $this->createAdminUser();
        User::factory()->create(['username' => 'JaExiste']);
        $this->actingAs($admin);

        $response = $this->postJson('/api/frontend/managed-accounts', [
            'fullname' => 'Participante',
            'username' => 'jaexiste',
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ], ['Idempotency-Key' => 'key-3']);

        $response->assertStatus(422)->assertJsonValidationErrors(['username']);
    }

    public function test_future_birthdate_is_rejected(): void
    {
        [$contest, $site] = $this->contestWithSite();
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $response = $this->postJson('/api/frontend/managed-accounts', [
            'fullname' => 'Participante',
            'username' => 'participante-futuro',
            'birthdate' => now()->addDay()->format('Y-m-d'),
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ], ['Idempotency-Key' => 'key-4']);

        $response->assertStatus(422)->assertJsonValidationErrors(['birthdate']);
    }

    public function test_impossible_calendar_date_is_rejected(): void
    {
        [$contest, $site] = $this->contestWithSite();
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $response = $this->postJson('/api/frontend/managed-accounts', [
            'fullname' => 'Participante',
            'username' => 'participante-impossivel',
            'birthdate' => '2023-02-30',
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ], ['Idempotency-Key' => 'key-5']);

        $response->assertStatus(422)->assertJsonValidationErrors(['birthdate']);
    }

    public function test_soft_deleted_contest_is_rejected(): void
    {
        [$contest, $site] = $this->contestWithSite();
        $contest->delete();
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $response = $this->postJson('/api/frontend/managed-accounts', [
            'fullname' => 'Participante',
            'username' => 'participante-contest-removido',
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ], ['Idempotency-Key' => 'key-6']);

        $response->assertStatus(422)->assertJsonValidationErrors(['contest_id']);
    }

    public function test_soft_deleted_site_is_rejected(): void
    {
        [$contest, $site] = $this->contestWithSite();
        $site->delete();
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $response = $this->postJson('/api/frontend/managed-accounts', [
            'fullname' => 'Participante',
            'username' => 'participante-site-removido',
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ], ['Idempotency-Key' => 'key-7']);

        $response->assertStatus(422)->assertJsonValidationErrors(['site_id']);
    }

    public function test_concurrent_duplicate_username_race_returns_clean_422_not_a_500(): void
    {
        [$contest, $site] = $this->contestWithSite();
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        // The username pre-check is a plain SELECT, not a lock, so it
        // cannot see a row inserted by a truly concurrent request that ran
        // its own pre-check at the same instant. Simulate exactly that
        // race deterministically: a `creating` listener plants a colliding
        // row with the *exact* same username right before this request's
        // own INSERT runs, so THIS request's INSERT is the one that hits
        // the DB's unique index. Previously that raw QueryException
        // propagated out of IdempotencyStore::handle() as an uncaught 500;
        // it must now surface as the same clean 422 an ordinary
        // duplicate-username request gets.
        User::creating(function (User $creating) {
            if ($creating->username === 'participante-corrida') {
                DB::table('users')->insert([
                    'fullname' => 'Concorrente',
                    'username' => 'participante-corrida',
                    'password' => bcrypt('x'),
                    'user_type' => 'team',
                    'is_enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        try {
            $response = $this->postJson('/api/frontend/managed-accounts', [
                'fullname' => 'Participante',
                'username' => 'participante-corrida',
                'contest_id' => $contest->id,
                'site_id' => $site->id,
            ], ['Idempotency-Key' => 'key-8']);

            $response->assertStatus(422)->assertJsonValidationErrors(['username']);
        } finally {
            User::flushEventListeners();
        }
    }

    // --- Criterio 5: idempotencia impede conta duplicada apos "timeout"

    public function test_idempotency_key_is_required(): void
    {
        [$contest, $site] = $this->contestWithSite();
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $this->postJson('/api/frontend/managed-accounts', [
            'fullname' => 'Participante',
            'username' => 'participante-sem-chave',
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ])->assertStatus(400);
    }

    public function test_repeating_the_same_idempotency_key_and_payload_does_not_create_a_second_account(): void
    {
        [$contest, $site] = $this->contestWithSite();
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $payload = [
            'fullname' => 'Participante Repetido',
            'username' => 'participante-repetido',
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ];

        $first = $this->postJson('/api/frontend/managed-accounts', $payload, ['Idempotency-Key' => 'same-key'])->assertCreated();
        $second = $this->postJson('/api/frontend/managed-accounts', $payload, ['Idempotency-Key' => 'same-key'])->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.activation_url'), $second->json('data.activation_url'));
        $this->assertSame(1, User::where('username', 'participante-repetido')->count());
    }

    public function test_reusing_the_same_idempotency_key_with_a_different_payload_is_409(): void
    {
        [$contest, $site] = $this->contestWithSite();
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $this->postJson('/api/frontend/managed-accounts', [
            'fullname' => 'Participante A',
            'username' => 'participante-a',
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ], ['Idempotency-Key' => 'shared-key'])->assertCreated();

        $this->postJson('/api/frontend/managed-accounts', [
            'fullname' => 'Participante B',
            'username' => 'participante-b',
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ], ['Idempotency-Key' => 'shared-key'])->assertStatus(409);

        $this->assertSame(0, User::where('username', 'participante-b')->count());
    }

    // --- Criterio 2: aniversario de 18 anos ------------------------------

    public function test_privacy_locked_flips_immediately_before_and_after_the_18th_birthday(): void
    {
        [$contest, $site] = $this->contestWithSite();
        $admin = $this->createAdminUser();
        $user = User::factory()->managed('2008-06-15', $admin->user_id)->create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ]);
        $this->actingAs($admin);

        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 14, 12, 0, 0, config('app.timezone')));
        $before = $this->getJson('/api/frontend/managed-accounts')->assertOk();
        $item = collect($before->json('data.items'))->firstWhere('id', $user->user_id);
        $this->assertTrue($item['privacy_locked']);
        $this->assertFalse($item['external_linking_allowed']);
        $this->assertSame('private', $item['visibility']);

        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 15, 0, 0, 1, config('app.timezone')));
        $after = $this->getJson('/api/frontend/managed-accounts')->assertOk();
        $item = collect($after->json('data.items'))->firstWhere('id', $user->user_id);
        $this->assertFalse($item['privacy_locked']);
        $this->assertTrue($item['external_linking_allowed']);
        // Turning 18 lifts eligibility only -- visibility never auto-flips
        // to public (no "make public" mechanism exists in this delivery).
        $this->assertSame('private', $item['visibility']);
    }

    public function test_unknown_birthdate_remains_locked_regardless_of_time(): void
    {
        [$contest, $site] = $this->contestWithSite();
        $admin = $this->createAdminUser();
        $user = User::factory()->managed(null, $admin->user_id)->create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
        ]);
        $this->actingAs($admin);

        $response = $this->getJson('/api/frontend/managed-accounts')->assertOk();
        $item = collect($response->json('data.items'))->firstWhere('id', $user->user_id);
        $this->assertTrue($item['privacy_locked']);
        $this->assertFalse($item['external_linking_allowed']);
    }

    public function test_search_filters_by_fullname_or_username(): void
    {
        [$contest, $site] = $this->contestWithSite();
        $admin = $this->createAdminUser();
        User::factory()->managed(null, $admin->user_id)->create(['contest_id' => $contest->id, 'site_id' => $site->id, 'fullname' => 'Ana Silva', 'username' => 'anasilva']);
        User::factory()->managed(null, $admin->user_id)->create(['contest_id' => $contest->id, 'site_id' => $site->id, 'fullname' => 'Bruno Souza', 'username' => 'brunosouza']);
        $this->actingAs($admin);

        $response = $this->getJson('/api/frontend/managed-accounts?q=ana')->assertOk();
        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('Ana Silva', $items[0]['fullname']);
    }

    public function test_listing_does_not_include_non_managed_accounts(): void
    {
        $admin = $this->createAdminUser();
        User::factory()->create(['user_type' => 'team']); // self-registered, managed_by null
        $this->actingAs($admin);

        $response = $this->getJson('/api/frontend/managed-accounts')->assertOk();
        $this->assertSame(0, $response->json('data.meta.total'));
    }
}
