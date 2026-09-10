<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use Tests\TestCase;

class FrontendPresentationTest extends TestCase
{
    public function test_configuration_page_exposes_working_actions_and_real_timer(): void
    {
        $this->actingAs($this->createAdminUser())->get('/backend/configurations')->assertOk()
            ->assertSee('Referências do sistema')
            ->assertSee('<contest-timer>', false)
            ->assertSee('href="/scoreboard/export"', false)
            ->assertDontSee('Salvar Configurações')
            ->assertDontSee('id="timer"', false);
    }

    public function test_recovery_page_does_not_offer_a_mail_delivery_that_is_not_implemented(): void
    {
        $this->get('/password/reset')->assertOk()
            ->assertSee('Recuperação por e-mail indisponível')
            ->assertSee('solicitar uma nova senha')
            ->assertDontSee('action="'.route('password.email').'"', false);
    }

    public function test_admin_submission_list_links_to_source_and_distinguishes_queued_from_judged(): void
    {
        $contest = Contest::factory()->create();
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id]);
        $answer = Answer::factory()->create(['contest_id' => $contest->id, 'short_name' => 'RE', 'is_accepted' => false]);
        $owner = $this->createTestUser();
        $attributes = ['contest_id' => $contest->id, 'problem_id' => $problem->id, 'language_id' => $language->id, 'user_id' => $owner->user_id];
        $run = Run::factory()->create($attributes + ['answer_id' => $answer->id, 'status' => 'judged']);
        Run::factory()->create($attributes + ['answer_id' => null, 'status' => 'pending']);

        $this->actingAs($this->createAdminUser())->get('/backend/submissions')->assertOk()
            ->assertSee('href="'.route('submission.show', $run->id).'"', false)
            ->assertSee('Erro de execução')->assertSee('Na fila')
            ->assertDontSee('title="Rejulgar"', false)
            ->assertDontSee('Todos os Times');
    }

    public function test_public_and_management_pages_have_one_primary_heading(): void
    {
        $this->actingAs($this->createAdminUser());
        foreach (['/', '/exercises', '/scoreboard', '/submissions', '/clarifications', '/ajuda', '/wizard', '/backend/users', '/backend/teams', '/backend/exercises', '/backend/configurations', '/backend/problem-bank', '/backend/import-boca', '/backend/contest-wizard', '/backend/submissions', '/backend/clarifications', '/judge/runs', '/staff/tasks', '/profile', '/register', '/password/reset'] as $path) {
            $response = $this->get($path)->assertOk();
            $this->assertSame(1, preg_match_all('/<h1(?:\s|>)/', $response->getContent()), $path.' should have one page heading');
        }
    }
}
