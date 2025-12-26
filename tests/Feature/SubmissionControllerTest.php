<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubmissionControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test guests are redirected from the submissions page.
     */
    public function test_guest_is_redirected_from_submissions_page()
    {
        $response = $this->get(route('submissions'));
        $response->assertRedirect(route('login'));
    }

    /**
     * Test authenticated user can view their submissions.
     */
    public function test_authenticated_user_can_view_submissions()
    {
        $user = $this->createTestUser();
        
        // Mocking the database schema and data for the test
        $this->setupDatabaseForSubmissions($user->user_id);

        $response = $this->actingAs($user)->get(route('submissions'));

        $response->assertStatus(200);
        $response->assertViewIs('submissions');
        $response->assertViewHas('submissions', function ($submissions) {
            return $submissions->count() === 1;
        });
        $response->assertViewHas('totalCount', 1);
        $response->assertViewHas('acceptedCount', 1);
    }
    
    /**
     * Helper to set up the complex database state needed for submissions
     */
    private function setupDatabaseForSubmissions($userId)
    {
        DB::table('contests')->insert(['id' => 1, 'name' => 'Test Contest']);
        DB::table('sites')->insert(['id' => 1, 'contest_id' => 1, 'name' => 'Main Site']);
        DB::table('problems')->insert(['id' => 1, 'contest_id' => 1, 'name' => 'Test Problem', 'short_name' => 'A', 'basename' => 'p1']);
        DB::table('languages')->insert(['id' => 1, 'contest_id' => 1, 'name' => 'PHP', 'extension' => 'php']);
        DB::table('answers')->insert(['id' => 1, 'contest_id' => 1, 'name' => 'Accepted', 'short_name' => 'AC', 'is_accepted' => true]);

        DB::table('runs')->insert([
            'user_id' => $userId,
            'contest_id' => 1,
            'site_id' => 1,
            'problem_id' => 1,
            'language_id' => 1,
            'answer_id' => 1,
            'run_number' => 1,
            'filename' => 'test.php',
            'source_file' => '/tmp/test.php',
            'source_hash' => 'hash',
            'contest_time' => 120,
            'status' => 'judged',
        ]);
    }
}
