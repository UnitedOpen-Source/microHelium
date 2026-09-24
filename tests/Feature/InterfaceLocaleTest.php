<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #397 -- o idioma da interface, de ponta a ponta.
 *
 * O que a issue mediu: `locale` padrao `en` com a interface toda em
 * portugues, `<html lang="pt-BR">` fixo, e nenhuma tela que mudasse de
 * idioma. Aqui: o padrao e pt_BR, o `lang` segue o idioma de verdade, e o
 * seletor troca a area do competidor inteira para espanhol.
 */
class InterfaceLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_locale_is_the_language_the_interface_is_written_in(): void
    {
        $this->assertSame('pt_BR', config('app.locale'));
        $this->assertSame('pt_BR', config('app.fallback_locale'));

        $this->get('/login')
            ->assertOk()
            ->assertSee('<html lang="pt-BR"', false)
            ->assertSee('Bem-vindo de volta!');
    }

    public function test_framework_validation_messages_come_out_in_portuguese_by_default(): void
    {
        // A hipotese da issue, medida: antes desta mudanca a mensagem saia
        // "The locale field is required." ao lado de rotulos em portugues --
        // e saia assim mesmo com APP_LOCALE=pt_BR, porque o arquivo pt_BR
        // era uma copia em ingles.
        $this->from('/login')->post('/locale', [])
            ->assertSessionHasErrors(['locale' => 'O campo idioma é obrigatório.']);

        $this->withSession(['locale' => 'es'])->from('/login')->post('/locale', [])
            ->assertSessionHasErrors(['locale' => 'El campo idioma es obligatorio.']);
    }

    public function test_the_selector_switches_the_page_and_its_lang_attribute_to_spanish(): void
    {
        $this->from('/login')->post('/locale', ['locale' => 'es'])
            ->assertRedirect('/login')
            ->assertSessionHas('locale', 'es');

        $this->get('/login')
            ->assertOk()
            ->assertSee('<html lang="es"', false)
            ->assertSee('¡Bienvenido de nuevo!')
            ->assertSee('Contraseña')
            ->assertDontSee('Bem-vindo de volta!');
    }

    public function test_an_unsupported_locale_is_refused_and_changes_nothing(): void
    {
        $this->from('/login')->post('/locale', ['locale' => 'en'])
            ->assertSessionHasErrors('locale')
            ->assertSessionMissing('locale');

        $this->withSession(['locale' => 'xx'])->get('/login')
            ->assertSee('<html lang="pt-BR"', false);
    }

    public function test_the_selector_offers_each_language_named_in_itself_and_marks_the_current_one(): void
    {
        $this->withSession(['locale' => 'es'])->get('/login')
            ->assertSee('value="pt_BR" lang="pt-BR" aria-pressed="false"', false)
            ->assertSee('value="es" lang="es" aria-pressed="true"', false)
            ->assertSee('Português')
            ->assertSee('Español');
    }

    public function test_the_browser_catalog_carries_the_vue_strings_of_the_current_locale_only(): void
    {
        $this->get('/login')->assertSee('<script type="application/json" id="i18n-catalog"', false)
            ->assertDontSee('Cargando competencia');

        $this->withSession(['locale' => 'es'])->get('/login')
            ->assertSee('Cargando competencia', false)
            // So o catalogo do Vue vai ao navegador, e nao o do Blade.
            ->assertDontSee('"Iniciar sesi', false);
    }

    public function test_the_competitor_flow_renders_in_spanish(): void
    {
        $contest = Contest::factory()->create();
        $problem = Problem::factory()->create(['contest_id' => $contest->id]);
        $language = Language::factory()->create(['contest_id' => $contest->id]);
        $answer = Answer::factory()->create(['contest_id' => $contest->id, 'short_name' => 'AC', 'is_accepted' => true]);
        $team = $this->createTestUser(['user_type' => 'team', 'contest_id' => $contest->id]);
        $run = Run::factory()->create([
            'contest_id' => $contest->id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'answer_id' => $answer->id,
            'user_id' => $team->user_id,
            'status' => 'judged',
            'source_file' => 'sources/does-not-exist.c',
        ]);

        $session = ['locale' => 'es'];

        $this->actingAs($team)->withSession($session)->get('/home')
            ->assertOk()->assertSee('Envíos recientes')->assertSee('Marcador');
        $this->actingAs($team)->withSession($session)->get('/exercises')
            ->assertOk()->assertSee('Lista de problemas');
        $this->actingAs($team)->withSession($session)->get("/exercise/{$problem->id}")
            ->assertOk()->assertSee('Límites del problema');
        $this->actingAs($team)->withSession($session)->get("/submit/{$problem->id}")
            ->assertOk()->assertSee('Lenguaje de programación');
        $this->actingAs($team)->withSession($session)->get('/submissions')
            ->assertOk()->assertSee('Mis envíos');
        $this->actingAs($team)->withSession($session)->get("/submission/{$run->id}")
            ->assertOk()->assertSee('Código fuente');
        $this->actingAs($team)->withSession($session)->get('/scoreboard')
            ->assertOk()->assertSee('Marcador de la competencia');
    }

    public function test_messages_the_controllers_send_to_the_competitor_follow_the_locale(): void
    {
        $this->withSession(['locale' => 'es'])->from('/login')
            ->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'Credenciales inválidas.']);
    }
}
