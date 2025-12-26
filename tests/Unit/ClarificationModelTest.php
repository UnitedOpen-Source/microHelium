<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Clarification;
use App\Models\Contest;
use App\Models\Site;
use Helium\User;
use App\Models\Problem;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ClarificationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_clarification_belongs_to_a_contest()
    {
        $contest = Contest::factory()->create();
        $clarification = Clarification::factory()->for($contest)->create();
        $this->assertInstanceOf(Contest::class, $clarification->contest);
    }

    public function test_clarification_belongs_to_a_site()
    {
        $site = Site::factory()->create();
        $clarification = Clarification::factory()->for($site)->create();
        $this->assertInstanceOf(Site::class, $clarification->site);
    }

    public function test_clarification_belongs_to_a_user()
    {
        $user = User::factory()->create();
        $clarification = Clarification::factory()->for($user)->create();
        $this->assertInstanceOf(User::class, $clarification->user);
    }

    public function test_clarification_belongs_to_a_problem()
    {
        $problem = Problem::factory()->create();
        $clarification = Clarification::factory()->for($problem)->create();
        $this->assertInstanceOf(Problem::class, $clarification->problem);
    }

    public function test_clarification_belongs_to_a_judge()
    {
        $judge = User::factory()->create(['user_type' => 'judge']);
        $clarification = Clarification::factory()->create(['judge_id' => $judge->user_id]);
        $this->assertInstanceOf(User::class, $clarification->judge);
    }

    public function test_clarification_belongs_to_a_judge_site()
    {
        $site = Site::factory()->create();
        $clarification = Clarification::factory()->create(['judge_site_id' => $site->id]);
        $this->assertInstanceOf(Site::class, $clarification->judgeSite);
    }

    public function test_is_pending()
    {
        $clarification = Clarification::factory()->create(['status' => 'pending']);
        $this->assertTrue($clarification->isPending());
        $clarification->status = 'answered';
        $this->assertFalse($clarification->isPending());
    }

    public function test_is_answered()
    {
        $clarification = Clarification::factory()->create(['status' => 'answered']);
        $this->assertTrue($clarification->isAnswered());
        $clarification->status = 'broadcast_site';
        $this->assertTrue($clarification->isAnswered());
        $clarification->status = 'broadcast_all';
        $this->assertTrue($clarification->isAnswered());
        $clarification->status = 'pending';
        $this->assertFalse($clarification->isAnswered());
    }

    public function test_is_broadcast()
    {
        $clarification = Clarification::factory()->create(['status' => 'broadcast_site']);
        $this->assertTrue($clarification->isBroadcast());
        $clarification->status = 'broadcast_all';
        $this->assertTrue($clarification->isBroadcast());
        $clarification->status = 'answered';
        $this->assertFalse($clarification->isBroadcast());
    }

    public function test_get_next_clarification_number()
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create();
        Clarification::factory()->create(['contest_id' => $contest->id, 'site_id' => $site->id, 'clarification_number' => 5]);
        $this->assertEquals(6, Clarification::getNextClarificationNumber($contest->id, $site->id));
    }

    public function test_get_next_clarification_number_for_new_contest()
    {
        $contest = Contest::factory()->create();
        $site = Site::factory()->create();
        $this->assertEquals(1, Clarification::getNextClarificationNumber($contest->id, $site->id));
    }
}
