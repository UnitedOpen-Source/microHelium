<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Site;
use Helium\User;
use Tests\TestCase;

/**
 * Issue #224 -- a guarda de rascunho só existe se a marcação chegar à página
 * e o módulo estiver ligado.
 *
 * `tests/Frontend/draft-form.test.js` prova que o módulo funciona sobre a
 * marcação que ele mesmo monta. Isso é metade: um módulo correto que
 * nenhuma tela marca, ou marcado numa tela mas nunca inicializado, passaria
 * naquele arquivo do mesmo jeito e protegeria exatamente nada.
 *
 * É a mesma armadilha do #144, quando toda a aplicação rodou com JavaScript
 * morto porque nada afirmava que ele rodava de verdade.
 */
class DraftGuardMarkupTest extends TestCase
{
    private function fonte(string $relativo): string
    {
        $caminho = base_path($relativo);

        $this->assertFileExists($caminho);

        return (string) file_get_contents($caminho);
    }

    public function test_the_module_is_wired_into_the_page_bootstrap(): void
    {
        $ui = $this->fonte('resources/js/ui.js');

        $this->assertStringContainsString("import { initializeDraftForms } from './ui/draft-form.js';", $ui);
        $this->assertStringContainsString('initializeDraftForms();', $ui);
    }

    public function test_the_contest_submit_page_marks_the_code_field(): void
    {
        $contest = Contest::factory()->running(30)->create();
        Site::factory()->create(['contest_id' => $contest->id]);
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        Language::factory()->create(['contest_id' => $contest->id, 'is_active' => true]);

        $equipe = User::create([
            'fullname' => 'Equipe de Teste',
            'username' => 'equipe-rascunho',
            'email' => 'equipe-rascunho@test.test',
            'password' => bcrypt('senha-de-teste'),
            'user_type' => 'team',
            'is_enabled' => true,
            'contest_id' => $contest->id,
        ]);

        $html = $this->actingAs($equipe)->get("/submit/{$problem->id}")->assertOk()->getContent();

        // O campo do código, e não qualquer campo: é o que se perde.
        $this->assertMatchesRegularExpression(
            '/<textarea[^>]*name="code_text"[^>]*data-draft-field/s',
            $html,
            'o campo onde a equipe cola a solução precisa estar marcado'
        );
    }

    public function test_the_clarification_question_is_marked(): void
    {
        $contest = Contest::factory()->running(30)->create();
        Site::factory()->create(['contest_id' => $contest->id]);
        Problem::factory()->create(['contest_id' => $contest->id]);

        $equipe = User::create([
            'fullname' => 'Equipe que Pergunta',
            'username' => 'equipe-pergunta',
            'email' => 'equipe-pergunta@test.test',
            'password' => bcrypt('senha-de-teste'),
            'user_type' => 'team',
            'is_enabled' => true,
            'contest_id' => $contest->id,
        ]);

        $html = $this->actingAs($equipe)->get('/clarifications')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<textarea\s[^>]*name="question"[^>]*data-draft-field/s',
            $html,
            'uma pergunta de dois mil caracteres escrita com cuidado também se perde'
        );
    }
}
