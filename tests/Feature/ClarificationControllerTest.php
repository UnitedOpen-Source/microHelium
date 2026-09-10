<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Helium\User;

class ClarificationControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // The store method requires a contest and a site to exist.
        DB::table('contests')->insert(['id' => 1, 'name' => 'Test Contest', 'is_active' => true, 'duration' => 300, 'start_time' => now()]);
        DB::table('sites')->insert(['id' => 1, 'contest_id' => 1, 'name' => 'Main Site']);
    }

    /**
     * Test the clarification index page.
     */
    public function test_clarification_index_page_loads()
    {
        $response = $this->get('/clarifications');

        $response->assertStatus(200);
        $response->assertViewIs('clarifications');
        $response->assertViewHasAll(['clarifications', 'exercises']);
    }

    /**
     * Test a guest cannot post a clarification.
     */
    public function test_guest_cannot_store_a_clarification()
    {
        $response = $this->post('/clarifications', ['question' => 'Help me?']);
        
        $response->assertRedirect('/login');
    }

    /**
     * Test an authenticated user can store a clarification.
     */
    public function test_authenticated_user_can_store_a_clarification()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
                         ->post('/clarifications', ['question' => 'This is a test question']);
        
        $response->assertRedirect(route('clarifications'));
        $response->assertSessionHas('success', 'Pergunta enviada com sucesso!');

        $this->assertDatabaseHas('clarifications', [
            'user_id' => $user->user_id,
            'question' => 'This is a test question',
            'status' => 'pending'
        ]);
    }

    /**
     * Test storing a clarification fails if no contest is set up.
     */
    public function test_storing_clarification_fails_gracefully_without_contest()
    {
        // Clear the tables for this specific test
        DB::table('sites')->delete();
        DB::table('contests')->delete();

        $user = User::factory()->create();

        $response = $this->actingAs($user)
                         ->post('/clarifications', ['question' => 'This should fail']);
        
        $response->assertRedirect(route('clarifications'));
        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('clarifications', ['question' => 'This should fail']);
    }

    /**
     * store() previously always tagged a new clarification with "the first
     * site associated with the contest," regardless of which site the
     * asking user actually belonged to -- which would have made site-scoped
     * clarification answering (issue #18) show the wrong (or no) questions
     * to a site coordinator whose site wasn't site id 1.
     */
    public function test_clarification_is_tagged_with_the_users_own_site_not_the_contests_first_site()
    {
        DB::table('sites')->insert(['id' => 2, 'contest_id' => 1, 'name' => 'Second Site']);
        $user = User::factory()->create(['site_id' => 2]);

        $response = $this->actingAs($user)->post('/clarifications', ['question' => 'Which site am I tagged with?']);

        $response->assertRedirect(route('clarifications'));
        $this->assertDatabaseHas('clarifications', [
            'question' => 'Which site am I tagged with?',
            'site_id' => 2,
        ]);
    }

    /**
     * Nothing deactivates other contests when one is activated, so more
     * than one row can have is_active=true. If the resolved "active"
     * contest isn't the one the user's own site belongs to, using that
     * site anyway would insert a clarification whose contest_id and
     * site_id disagree on which contest they belong to -- falls back to
     * the resolved contest's own first site instead.
     */
    public function test_falls_back_to_the_active_contests_own_site_when_users_site_belongs_to_a_different_contest()
    {
        DB::table('contests')->insert(['id' => 2, 'name' => 'Other Contest', 'is_active' => true, 'duration' => 300, 'start_time' => now()]);
        DB::table('sites')->insert(['id' => 2, 'contest_id' => 2, 'name' => 'Other Contest Site']);
        $user = User::factory()->create(['site_id' => 2]);

        $response = $this->actingAs($user)->post('/clarifications', ['question' => 'Which contest/site am I tagged with?']);

        $response->assertRedirect(route('clarifications'));
        $clarification = DB::table('clarifications')->where('question', 'Which contest/site am I tagged with?')->first();
        $this->assertNotNull($clarification);
        $site = DB::table('sites')->where('id', $clarification->site_id)->first();
        $this->assertSame($clarification->contest_id, $site->contest_id, 'clarification contest_id and its site must belong to the same contest');
    }

    /**
     * Test storing a clarification fails if the active contest has no site.
     */
    public function test_storing_clarification_fails_if_active_contest_has_no_site()
    {
        // Ensure a contest exists, but no site
        DB::table('sites')->delete();

        $user = User::factory()->create();

        $response = $this->actingAs($user)
                         ->post('/clarifications', ['question' => 'This should also fail']);
        
        $response->assertRedirect(route('clarifications'));
        $response->assertSessionHas('error', 'O concurso ativo nao possui um site configurado.');
        $this->assertDatabaseMissing('clarifications', ['question' => 'This should also fail']);
    }
}