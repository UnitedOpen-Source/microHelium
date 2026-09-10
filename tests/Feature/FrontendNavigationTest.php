<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrontendNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_navigation_and_help_do_not_advertise_admin_or_dead_profile_links(): void
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
        $this->get('/home')->assertSee('href="/backend/users"', false)->assertDontSee('href="/profile"', false);
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
}
