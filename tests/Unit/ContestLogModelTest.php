<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\ContestLog;
use App\Models\Contest;
use App\Models\Site;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ContestLogModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_contest_log_belongs_to_a_contest()
    {
        $contest = Contest::factory()->create();
        $log = ContestLog::factory()->for($contest)->create();
        $this->assertInstanceOf(Contest::class, $log->contest);
    }

    public function test_contest_log_belongs_to_a_site()
    {
        $site = Site::factory()->create();
        $log = ContestLog::factory()->for($site)->create();
        $this->assertInstanceOf(Site::class, $log->site);
    }

    public function test_contest_log_belongs_to_a_user()
    {
        $user = User::factory()->create();
        $log = ContestLog::factory()->for($user)->create();
        $this->assertInstanceOf(User::class, $log->user);
    }

    public function test_log_static_method()
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create();
        $user = User::factory()->create();

        $log = ContestLog::log(
            $contest->id,
            'info',
            'Test message',
            $site->id,
            $user->user_id,
            '127.0.0.1',
            ['key' => 'value']
        );

        $this->assertDatabaseHas('contest_logs', [
            'contest_id' => $contest->id,
            'type' => 'info',
            'message' => 'Test message',
            'site_id' => $site->id,
            'user_id' => $user->user_id,
            'ip_address' => '127.0.0.1',
            'context' => json_encode(['key' => 'value']),
        ]);
    }

    public function test_error_static_method()
    {
        $contest = Contest::factory()->create();
        ContestLog::error($contest->id, 'Error message');
        $this->assertDatabaseHas('contest_logs', [
            'contest_id' => $contest->id,
            'type' => 'error',
            'message' => 'Error message',
        ]);
    }

    public function test_warning_static_method()
    {
        $contest = Contest::factory()->create();
        ContestLog::warning($contest->id, 'Warning message');
        $this->assertDatabaseHas('contest_logs', [
            'contest_id' => $contest->id,
            'type' => 'warning',
            'message' => 'Warning message',
        ]);
    }

    public function test_info_static_method()
    {
        $contest = Contest::factory()->create();
        ContestLog::info($contest->id, 'Info message');
        $this->assertDatabaseHas('contest_logs', [
            'contest_id' => $contest->id,
            'type' => 'info',
            'message' => 'Info message',
        ]);
    }
}