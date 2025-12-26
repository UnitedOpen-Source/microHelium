<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Site;
use App\Models\Contest;
use Helium\User;
use App\Models\Run;
use App\Models\Clarification;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SiteModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_belongs_to_a_contest()
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->for($contest)->create();
        $this->assertInstanceOf(Contest::class, $site->contest);
    }

    public function test_site_has_many_users()
    {
        $site = Site::factory()->create();
        User::factory()->for($site)->count(3)->create();
        $this->assertCount(3, $site->users);
    }

    public function test_site_has_many_runs()
    {
        $site = Site::factory()->create();
        Run::factory()->for($site)->count(3)->create();
        $this->assertCount(3, $site->runs);
    }

    public function test_site_has_many_clarifications()
    {
        $site = Site::factory()->create();
        Clarification::factory()->for($site)->count(3)->create();
        $this->assertCount(3, $site->clarifications);
    }

    public function test_site_has_many_tasks()
    {
        $site = Site::factory()->create();
        Task::factory()->for($site)->count(3)->create();
        $this->assertCount(3, $site->tasks);
    }

    public function test_get_effective_duration()
    {
        $contest = Contest::factory()->create(['duration' => 300]);
        $siteWithDuration = Site::factory()->for($contest)->create(['duration' => 180]);
        $siteWithoutDuration = Site::factory()->for($contest)->create(['duration' => null]);

        $this->assertEquals(180, $siteWithDuration->getEffectiveDuration());
        $this->assertEquals(300, $siteWithoutDuration->getEffectiveDuration());
    }

    public function test_get_effective_freeze_time()
    {
        $contest = Contest::factory()->create(['freeze_time' => 60]);
        $siteWithFreeze = Site::factory()->for($contest)->create(['freeze_time' => 30]);
        $siteWithoutFreeze = Site::factory()->for($contest)->create(['freeze_time' => null]);

        $this->assertEquals(30, $siteWithFreeze->getEffectiveFreezeTime());
        $this->assertEquals(60, $siteWithoutFreeze->getEffectiveFreezeTime());
    }
}
