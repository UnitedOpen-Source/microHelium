<?php

namespace Tests\Feature\Backend;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProblemBankControllerTest extends TestCase
{
    use RefreshDatabase;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdminUser();
    }

    private function createTestProblem(array $attributes = [])
    {
        return DB::table('problem_bank')->insertGetId(array_merge([
            'code' => 'TEST001',
            'name' => 'Test Problem 1',
            'description' => 'Test description',
            'input_description' => 'Input',
            'output_description' => 'Output',
        ], $attributes));
    }

    public function test_admin_can_view_problem_bank_index()
    {
        $this->createTestProblem(['name' => 'My Test Problem']);

        $response = $this->actingAs($this->admin)->get(route('backend.problem-bank'));

        $response->assertStatus(200);
        $response->assertViewIs('backend.problem-bank');
        $response->assertViewHas('problems');
        $response->assertSee('My Test Problem');
    }

    public function test_admin_can_delete_a_problem()
    {
        $problemId = $this->createTestProblem();
        $this->assertDatabaseHas('problem_bank', ['id' => $problemId]);

        $response = $this->actingAs($this->admin)->delete(route('backend.problem-bank.destroy', $problemId));

        $response->assertRedirect(route('backend.problem-bank'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('problem_bank', ['id' => $problemId]);
    }

    public function test_admin_can_toggle_a_problem_status()
    {
        $problemId = $this->createTestProblem(['is_active' => true]);
        
        $problem = DB::table('problem_bank')->find($problemId);
        $this->assertTrue((bool)$problem->is_active);

        $response = $this->actingAs($this->admin)->post(route('backend.problem-bank.toggle', $problemId));
        
        $response->assertRedirect(route('backend.problem-bank'));
        $response->assertSessionHas('success');
        
        $updatedProblem = DB::table('problem_bank')->find($problemId);
        $this->assertFalse((bool)$updatedProblem->is_active);

        // Toggle it back
        $this->actingAs($this->admin)->post(route('backend.problem-bank.toggle', $problemId));
        $finalProblem = DB::table('problem_bank')->find($problemId);
        $this->assertTrue((bool)$finalProblem->is_active);
    }

    public function test_non_admin_cannot_manage_problem_bank()
    {
        $user = $this->createTestUser();
        $problemId = $this->createTestProblem();

        $this->actingAs($user);

        $this->get(route('backend.problem-bank'))->assertStatus(403);
        $this->delete(route('backend.problem-bank.destroy', $problemId))->assertStatus(403);
        $this->post(route('backend.problem-bank.toggle', $problemId))->assertStatus(403);
    }
}
