<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\TestCase as TestModel;
use App\Models\Problem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class TestCaseModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_test_case_belongs_to_a_problem()
    {
        $problem = Problem::factory()->create();
        $testCase = TestModel::factory()->for($problem)->create();
        $this->assertInstanceOf(Problem::class, $testCase->problem);
    }

    public function test_get_input_path()
    {
        $testCase = TestModel::factory()->create(['input_file' => 'path/to/input.in']);
        $expectedPath = storage_path('app/path/to/input.in');
        $this->assertEquals($expectedPath, $testCase->getInputPath());
    }

    public function test_get_output_path()
    {
        $testCase = TestModel::factory()->create(['output_file' => 'path/to/output.out']);
        $expectedPath = storage_path('app/path/to/output.out');
        $this->assertEquals($expectedPath, $testCase->getOutputPath());
    }
}
