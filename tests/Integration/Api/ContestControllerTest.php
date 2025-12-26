<?php

namespace Tests\Integration\Api;

use Tests\TestCase;
use App\Models\Contest;
use App\Models\Site;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ContestControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_paginated_contests()
    {
        Contest::factory()->count(20)->create(['is_public' => true]);
        Contest::factory()->count(5)->create(['is_public' => false]);
        $admin = User::factory()->create(['user_type' => 'admin']);
        $user = User::factory()->create();

        // Non-admin user should only see public contests
        Sanctum::actingAs($user);
        $response = $this->getJson('/api/contests');
        $response->assertStatus(200)
                 ->assertJsonCount(15, 'data')
                 ->assertJsonPath('total', 20);

        // Admin user should see all contests
        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/contests');
        $response->assertStatus(200)
                 ->assertJsonCount(15, 'data')
                 ->assertJsonPath('total', 25);
    }

    public function test_store_creates_new_contest_with_defaults()
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        Sanctum::actingAs($admin);
        $contestData = [
            'name' => 'New API Contest',
            'duration' => 300,
            'freeze_time' => 60,
            'penalty' => 20,
            'max_file_size' => 2048,
        ];

        $response = $this->postJson('/api/contests', $contestData);

        $response->assertStatus(201)
                 ->assertJsonPath('name', 'New API Contest');

        $contestId = $response->json('id');
        $this->assertDatabaseHas('contests', ['id' => $contestId]);
        $this->assertDatabaseHas('sites', ['contest_id' => $contestId]);
        $this->assertDatabaseHas('languages', ['contest_id' => $contestId]);
        $this->assertDatabaseHas('answers', ['contest_id' => $contestId]);
    }

    public function test_show_returns_contest_details()
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        Sanctum::actingAs($admin);
        $contest = Contest::factory()->create();
        $response = $this->getJson("/api/contests/{$contest->id}");

        $response->assertStatus(200)
                 ->assertJsonPath('id', $contest->id);
    }

    public function test_update_modifies_contest()
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        Sanctum::actingAs($admin);
        $contest = Contest::factory()->create(['name' => 'Original Name']);
        $updateData = ['name' => 'Updated Name'];

        $response = $this->putJson("/api/contests/{$contest->id}", $updateData);

        $response->assertStatus(200)
                 ->assertJsonPath('name', 'Updated Name');
        $this->assertDatabaseHas('contests', ['id' => $contest->id, 'name' => 'Updated Name']);
    }

    public function test_destroy_deletes_contest()
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        Sanctum::actingAs($admin);
        $contest = Contest::factory()->create();
        $response = $this->deleteJson("/api/contests/{$contest->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('contests', ['id' => $contest->id]);
    }

    public function test_activate_and_deactivate_contest()
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        Sanctum::actingAs($admin);
        $contest = Contest::factory()->create(['is_active' => false]);

        // Activate
        $response = $this->postJson("/api/contests/{$contest->id}/activate");
        $response->assertStatus(200)->assertJsonPath('contest.is_active', true);

        // Deactivate
        $response = $this->postJson("/api/contests/{$contest->id}/deactivate");
        $response->assertStatus(200)->assertJsonPath('contest.is_active', false);
    }

    public function test_status_returns_contest_status()
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        Sanctum::actingAs($admin);
        $contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()->subHour(), 'duration' => 120]);
        $response = $this->getJson("/api/contests/{$contest->id}/status");

        $response->assertStatus(200)
                 ->assertJsonPath('is_active', true)
                 ->assertJsonPath('is_running', true);
    }
}
