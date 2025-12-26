<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Task;
use App\Models\Contest;
use App\Models\Site;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TaskModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_belongs_to_a_contest()
    {
        $contest = Contest::factory()->create();
        $task = Task::factory()->for($contest)->create();
        $this->assertInstanceOf(Contest::class, $task->contest);
    }

    public function test_task_belongs_to_a_site()
    {
        $site = Site::factory()->create();
        $task = Task::factory()->for($site)->create();
        $this->assertInstanceOf(Site::class, $task->site);
    }

    public function test_task_belongs_to_a_user()
    {
        $user = User::factory()->create();
        $task = Task::factory()->for($user)->create();
        $this->assertInstanceOf(User::class, $task->user);
    }

    public function test_task_belongs_to_a_staff_member()
    {
        $staff = User::factory()->create(['user_type' => 'staff']);
        $task = Task::factory()->create(['staff_id' => $staff->user_id]);
        $this->assertInstanceOf(User::class, $task->staff);
    }

    public function test_task_belongs_to_a_staff_site()
    {
        $site = Site::factory()->create();
        $task = Task::factory()->create(['staff_site_id' => $site->id]);
        $this->assertInstanceOf(Site::class, $task->staffSite);
    }

    public function test_is_pending()
    {
        $task = Task::factory()->create(['status' => 'pending']);
        $this->assertTrue($task->isPending());
        $task->status = 'done';
        $this->assertFalse($task->isPending());
    }

    public function test_is_done()
    {
        $task = Task::factory()->create(['status' => 'done']);
        $this->assertTrue($task->isDone());
        $task->status = 'pending';
        $this->assertFalse($task->isDone());
    }

    public function test_get_next_task_number()
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create();
        Task::factory()->create(['contest_id' => $contest->id, 'site_id' => $site->id, 'task_number' => 5]);
        $this->assertEquals(6, Task::getNextTaskNumber($contest->id, $site->id));
    }

    public function test_get_next_task_number_for_new_contest()
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create();
        $this->assertEquals(1, Task::getNextTaskNumber($contest->id, $site->id));
    }
}
