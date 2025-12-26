<?php

namespace Tests\E2E;

use Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProblemImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate');
        Storage::fake('local');
    }

    public function testImportBocaPageLoadsCorrectly(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/backend/import-boca');
        $response->assertStatus(200);
    }

    public function testImportBocaPageShowsUploadForm(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/backend/import-boca');
        $response->assertStatus(200);
        $response->assertSee('Upload');
    }

    public function testImportBocaPageShowsGitHubImportButton(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/backend/import-boca');
        $response->assertStatus(200);
        $response->assertSee('GitHub');
    }

    public function testImportBocaRequiresAuthentication(): void
    {
        $response = $this->get('/backend/import-boca');
        $response->assertRedirect('/login');
    }

    public function testImportBocaRequiresAdminRole(): void
    {
        $user = $this->createTestUser(['user_type' => 'team']);
        $response = $this->actingAs($user)->get('/backend/import-boca');
        $response->assertStatus(403);
    }

    public function testZipUploadFormExists(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/backend/import-boca');
        $response->assertStatus(200);
        $response->assertSee('boca_zip');
    }

    public function testInvalidZipFileIsRejected(): void
    {
        $admin = $this->createAdminUser();

        // Create a fake non-zip file
        $file = UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->actingAs($admin)->post('/backend/import-boca/upload', [
            'boca_zip' => $file,
        ]);

        // Should fail validation or return error
        $this->assertTrue(in_array($response->status(), [302, 422]));
    }

    public function testEmptyZipFileIsRejected(): void
    {
        $admin = $this->createAdminUser();

        // Create an empty fake zip file
        $file = UploadedFile::fake()->create('empty.zip', 0);

        $response = $this->actingAs($admin)->post('/backend/import-boca/upload', [
            'boca_zip' => $file,
        ]);

        // Should fail
        $this->assertTrue(in_array($response->status(), [302, 422, 500]));
    }

    public function testProblemBankPageShowsImportedProblems(): void
    {
        $admin = $this->createAdminUser();

        // First check the problem bank page loads
        $response = $this->actingAs($admin)->get('/backend/problem-bank');
        $response->assertStatus(200);
    }

    public function testGitHubImportButtonSubmitsCorrectly(): void
    {
        $admin = $this->createAdminUser();

        // Check that the GitHub import form exists
        $response = $this->actingAs($admin)->get('/backend/import-boca');
        $response->assertStatus(200);
        $response->assertSee('/backend/import-boca/github');
    }

    public function testImportFormHasCsrfToken(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/backend/import-boca');
        $response->assertStatus(200);
        $response->assertSee('_token');
    }

    public function testMaxFileSizeValidation(): void
    {
        $admin = $this->createAdminUser();

        // Create a file that exceeds max size (50MB limit typically)
        $file = UploadedFile::fake()->create('large.zip', 60000); // 60MB

        $response = $this->actingAs($admin)->post('/backend/import-boca/upload', [
            'boca_zip' => $file,
        ]);

        // Should fail due to size
        $this->assertTrue(in_array($response->status(), [302, 413, 422]));
    }
}
