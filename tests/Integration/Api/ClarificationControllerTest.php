<?php

namespace Tests\Integration\Api;

use Tests\TestCase;
use App\Models\Clarification;
use App\Models\Contest;
use App\Models\Problem;
use App\Models\Site;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ClarificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_for_admin()
    {
        $contest = Contest::factory()->create();
        $admin = User::factory()->create(['user_type' => 'admin']);
        Clarification::factory()->count(5)->create(['contest_id' => $contest->id]);
        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/clarifications?contest_id={$contest->id}");
        $response->assertStatus(200)->assertJsonCount(5, 'data');
    }

    public function test_index_for_user()
    {
        $contest = Contest::factory()->create();
        $user = User::factory()->create();
        Clarification::factory()->count(3)->create(['contest_id' => $contest->id, 'user_id' => $user->user_id]);
        Clarification::factory()->count(2)->create(['contest_id' => $contest->id, 'status' => 'broadcast_all']);
        Clarification::factory()->count(1)->create(['contest_id' => $contest->id]); // Private one

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/clarifications?contest_id={$contest->id}");
        $response->assertStatus(200)->assertJsonCount(5, 'data'); // 3 own + 2 broadcast
    }

    public function test_store_clarification()
    {
        $contest = Contest::factory()->has(Site::factory())->create(['is_active' => true, 'start_time' => now()]);
        $user = User::factory()->create(['site_id' => $contest->sites()->first()->id]);
        Sanctum::actingAs($user);
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $data = [
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'question' => 'This is a test question.',
        ];
        $response = $this->postJson('/api/clarifications', $data);
        $response->assertStatus(201)->assertJsonPath('question', $data['question']);
    }

    public function test_store_fails_if_contest_not_running()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $contest = Contest::factory()->create(['is_active' => false]);
        $data = ['contest_id' => $contest->id, 'question' => 'wont work'];
        $response = $this->postJson('/api/clarifications', $data);
        $response->assertStatus(422);
    }

    public function test_show_clarification_for_admin()
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $clarification = Clarification::factory()->create();
        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/clarifications/{$clarification->id}");
        $response->assertStatus(200)->assertJsonPath('id', $clarification->id);
    }

    public function test_show_clarification_for_owner()
    {
        $user = User::factory()->create();
        $clarification = Clarification::factory()->create(['user_id' => $user->user_id]);
        Sanctum::actingAs($user);
        $response = $this->getJson("/api/clarifications/{$clarification->id}");
        $response->assertStatus(200);
    }

    public function test_show_clarification_unauthorized()
    {
        $user = User::factory()->create();
        $clarification = Clarification::factory()->create(); // Belongs to another user
        Sanctum::actingAs($user);
        $response = $this->getJson("/api/clarifications/{$clarification->id}");
        $response->assertStatus(403);
    }

    public function test_answer_clarification()
    {
        $judge = User::factory()->create(['user_type' => 'judge']);
        $clarification = Clarification::factory()->create();
        Sanctum::actingAs($judge);
        $data = ['answer' => 'This is the answer.'];
        $response = $this->putJson("/api/clarifications/{$clarification->id}/answer", $data);
        $response->assertStatus(200)->assertJsonPath('answer', $data['answer']);
    }

    public function test_answer_clarification_with_broadcast()
    {
        $judge = User::factory()->create(['user_type' => 'judge']);
        Sanctum::actingAs($judge);

        // Test site broadcast
        $clarification1 = Clarification::factory()->create();
        $data1 = ['answer' => 'Answer 1', 'broadcast' => 'site'];
        $this->putJson("/api/clarifications/{$clarification1->id}/answer", $data1)
             ->assertStatus(200)
             ->assertJsonPath('status', 'broadcast_site');

        // Test all broadcast
        $clarification2 = Clarification::factory()->create();
        $data2 = ['answer' => 'Answer 2', 'broadcast' => 'all'];
        $this->putJson("/api/clarifications/{$clarification2->id}/answer", $data2)
             ->assertStatus(200)
             ->assertJsonPath('status', 'broadcast_all');
    }

    public function test_destroy_clarification()
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $clarification = Clarification::factory()->create();
        Sanctum::actingAs($admin);
        $response = $this->deleteJson("/api/clarifications/{$clarification->id}");
        $response->assertStatus(204);
        $this->assertSoftDeleted('clarifications', ['id' => $clarification->id]);
    }

    public function test_pending_clarifications()
    {
        $contest = Contest::factory()->create();
        $judge = User::factory()->create(['user_type' => 'judge']);
        Clarification::factory()->count(3)->create(['contest_id' => $contest->id, 'status' => 'pending']);
        Clarification::factory()->count(2)->create(['contest_id' => $contest->id, 'status' => 'answered']);
        Sanctum::actingAs($judge);
        $response = $this->getJson("/api/clarifications/pending?contest_id={$contest->id}");
        $response->assertStatus(200)->assertJsonCount(3);
    }
}
