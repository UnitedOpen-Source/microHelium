<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrontendNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_navigation_and_help_do_not_advertise_admin_or_private_profile_links(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Pular para o conteúdo')
            ->assertSee('href="/wizard"', false)
            ->assertDontSee('href="/backend/users"', false)
            ->assertDontSee('href="/profile"', false);
        $this->get('/wizard')->assertOk()->assertSee('Primeiros passos')->assertDontSee('exercicio.html');
        $this->get('/ajuda')->assertOk()->assertSee('Perguntas frequentes');
    }

    public function test_admin_can_render_every_management_page_in_the_shared_shell(): void
    {
        $this->actingAs($this->createAdminUser());
        foreach (['exercises', 'users', 'teams', 'configurations', 'problem-bank', 'submissions', 'clarifications', 'import-boca', 'contest-wizard'] as $page) {
            $this->get('/backend/'.$page)->assertOk()->assertSee('id="main-content"', false);
        }
        $this->get('/home')->assertSee('href="/backend/users"', false)->assertSee('href="'.route('profile.edit').'"', false);
    }

    public function test_participant_has_no_admin_navigation_and_can_see_submission_history(): void
    {
        $this->actingAs($this->createTestUser());
        $this->get('/submissions')->assertOk()->assertSee('Minhas Submissões')->assertDontSee('href="/backend/users"', false);
    }

    public function test_missing_page_returns_designed_error_with_correct_status(): void
    {
        config(['app.debug' => false]);
        $this->get('/pagina-inexistente')->assertNotFound()->assertSee('Esse caminho não foi encontrado');
    }

    public function test_wizard_retains_submitted_values_and_accessible_selections_after_validation(): void
    {
        $this->actingAs($this->createAdminUser())
            ->withSession(['_old_input' => [
                'name' => 'Maratona revisada', 'start_time' => '2027-02-15T13:30',
                'duration' => '90', 'penalty' => '0', 'max_file_size' => '128',
                'freeze_time' => '10', 'languages' => ['cpp_gpp13'],
            ]])
            ->get('/backend/contest-wizard')->assertOk()
            ->assertSee('value="Maratona revisada"', false)
            ->assertSee('value="2027-02-15T13:30"', false)
            ->assertSee('value="90"', false)
            ->assertSee('value="0"', false)
            ->assertSee('name="languages[]" value="cpp_gpp13" class="sr-only" checked', false)
            ->assertSee('id="wizard-status"', false);
    }

    public function test_admin_validation_preserves_input_and_renders_field_error_summary(): void
    {
        $admin = $this->createAdminUser();
        $this->actingAs($admin)->from('/backend/users')->post('/backend/users', [
            'fullname' => 'Nome preservado', 'username' => $admin->username,
            'email' => 'duplicate-test@example.com', 'password' => 'test-password',
        ])->assertRedirect('/backend/users')->assertSessionHasErrors('username');
        $this->get('/backend/users')->assertOk()
            ->assertSee('data-error-summary', false)
            ->assertSee('data-error-field="username"', false)
            ->assertSee('value="Nome preservado"', false)
            ->assertDontSee('value="test-password"', false);
    }

}
