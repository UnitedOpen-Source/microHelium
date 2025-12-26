<?php

namespace Tests\Integration\Api;

use Tests\TestCase;
use App\Models\Contest;
use App\Models\Problem;
use App\Services\ProblemPackageService;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;

class ProblemControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['user_type' => 'admin']);
        Sanctum::actingAs($this->admin);
    }

    public function test_index_returns_paginated_problems()
    {
        $contest = Contest::factory()->create();
        Problem::factory()->count(10)->create(['contest_id' => $contest->id]);

        $response = $this->getJson("/api/problems?contest_id={$contest->id}");

        $response->assertStatus(200)
                 ->assertJsonCount(10, 'data');
    }

    public function test_store_creates_problem_from_package()
    {
        $this->mock(ProblemPackageService::class, function (MockInterface $mock) {
            $mock->shouldReceive('importFromZip')->once()->andReturn(new Problem(['id' => 1]));
        });

        $contest = Contest::factory()->create();
        Storage::fake('local');
        $file = UploadedFile::fake()->create('problem.zip', 1000, 'application/zip');

        $response = $this->postJson('/api/problems', [
            'contest_id' => $contest->id,
            'package' => $file,
        ]);

        $response->assertStatus(201);
    }

    public function test_show_returns_problem_details()
    {
        $problem = Problem::factory()->create();
        $response = $this->getJson("/api/problems/{$problem->id}");
        $response->assertStatus(200)->assertJsonPath('id', $problem->id);
    }

    public function test_update_modifies_problem()
    {
        $problem = Problem::factory()->create(['name' => 'Original Name']);
        $updateData = ['name' => 'Updated Name'];

        $response = $this->putJson("/api/problems/{$problem->id}", $updateData);

        $response->assertStatus(200)->assertJsonPath('name', 'Updated Name');
        $this->assertDatabaseHas('problems', ['id' => $problem->id, 'name' => 'Updated Name']);
    }

    public function test_destroy_deletes_problem()
    {
        $problem = Problem::factory()->create();
        $response = $this->deleteJson("/api/problems/{$problem->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('problems', ['id' => $problem->id]);
    }

    public function test_download_problem_description()
    {
        Storage::fake('app');
        $contest = Contest::factory()->create();
        $problem = Problem::factory()->create([
            'contest_id' => $contest->id,
            'basename' => 'test-problem',
            'description_file' => 'description.html'
        ]);
        $path = "problems/{$problem->contest_id}/{$problem->basename}/description/{$problem->description_file}";
        Storage::disk('app')->put($path, '<html><body>Problem description</body></html>');

        $response = $this->get("/api/problems/{$problem->id}/download");

        $response->assertStatus(200);
        $response->assertHeader('Content-Disposition', 'attachment; filename=description.html');
    }

    public function test_download_problem_description_not_found()
    {
        Storage::fake('app');
        $problem = Problem::factory()->create();
        $response = $this->get("/api/problems/{$problem->id}/download");
        $response->assertStatus(404);
    }

    public function test_export_problem_package()
    {
        $problem = Problem::factory()->create();
        $fakeZipPath = 'exports/exported_problem.zip';

        $this->instance(
            ProblemPackageService::class,
            $this->mock(ProblemPackageService::class, function (MockInterface $mock) use ($problem, $fakeZipPath) {
                $mock->shouldReceive('exportToZip')
                    ->withArgs(function ($arg) use ($problem) {
                        return $arg->id === $problem->id;
                    })
                    ->once()
                    ->andReturn($fakeZipPath);
            })
        );
        
        Storage::fake('app');
        Storage::disk('app')->put($fakeZipPath, 'zip_content');

        $response = $this->get("/api/problems/{$problem->id}/export");
        $response->assertStatus(200);
        $response->assertHeader('Content-Disposition', 'attachment; filename=exported_problem.zip');
    }
}
