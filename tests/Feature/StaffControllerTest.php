<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Site;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_user_cannot_access_the_staff_screen()
    {
        $team = $this->createTestUser(['user_type' => 'team']);

        $response = $this->actingAs($team)->get('/staff/tasks');

        $response->assertStatus(403);
    }

    public function test_staff_can_view_tasks_for_their_contest()
    {
        $contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()->subHour()]);
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $staff = $this->createTestUser(['user_type' => 'staff', 'contest_id' => $contest->id]);
        $team = $this->createTestUser(['user_type' => 'team', 'contest_id' => $contest->id, 'site_id' => $site->id]);

        Task::create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'user_id' => $team->user_id,
            'task_number' => 1,
            'description' => 'Deliver balloon for problem A',
            'contest_time' => 100,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($staff)->get('/staff/tasks');

        $response->assertStatus(200);
        $response->assertViewIs('staff.tasks');
        $response->assertSeeText('Deliver balloon for problem A');
    }

    public function test_staff_can_mark_a_task_complete()
    {
        $contest = Contest::factory()->create(['is_active' => true, 'start_time' => now()->subHour()]);
        $site = Site::factory()->create(['contest_id' => $contest->id]);
        $staff = $this->createTestUser(['user_type' => 'staff', 'contest_id' => $contest->id]);
        $team = $this->createTestUser(['user_type' => 'team', 'contest_id' => $contest->id, 'site_id' => $site->id]);

        $task = Task::create([
            'contest_id' => $contest->id,
            'site_id' => $site->id,
            'user_id' => $team->user_id,
            'task_number' => 1,
            'description' => 'Print submission',
            'contest_time' => 100,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($staff)->post("/staff/tasks/{$task->id}/complete");

        $response->assertRedirect(route('staff.tasks'));
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'done',
            'staff_id' => $staff->user_id,
        ]);
    }
}
