<?php

namespace Tests\Feature;

use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #275 -- o auto-cadastro deixa de entregar sessão.
 *
 * O que existia eram duas closures em `routes/web.php`, e cada um dos quatro
 * problemas era verificável por `route:list` ou por leitura:
 *
 *   1. sem throttle -- `POST /login` tinha `ThrottleRequests:5,1`,
 *      `POST /register` tinha apenas `web`;
 *   2. `DB::table()` direto, contornando o `$fillable`;
 *   3. `is_enabled => true` -- conta ativa sem convite e sem ativação,
 *      enquanto as contas gerenciadas do #47 nascem desabilitadas de
 *      propósito;
 *   4. `auth()->loginUsingId()` -- sessão sem decisão de ninguém.
 *
 * O SRS descreve, em F02, apenas o cadastro administrado (RF-F02-007) e a
 * conta gerenciada com ativação (RF-F02-008). A rota não aparecia em
 * requisito nenhum.
 */
class SelfRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $extra
     */
    private function cadastrar(array $extra = [], string $email = 'novo@example.com')
    {
        return $this->post('/register', array_merge([
            'fullname' => 'Equipe Nova',
            'username' => 'equipe-nova',
            'email' => $email,
            'password' => 'senha-bem-grande',
            'password_confirmation' => 'senha-bem-grande',
        ], $extra));
    }

    // ---------------------------------------------------------------
    // O ponto inteiro: a conta nasce desabilitada e sem sessão.
    // ---------------------------------------------------------------

    public function test_the_new_account_is_born_disabled(): void
    {
        $this->cadastrar()->assertRedirect(route('login'));

        $user = User::where('email', 'novo@example.com')->first();

        $this->assertNotNull($user, 'o cadastro nao criou conta nenhuma');
        $this->assertFalse((bool) $user->is_enabled, 'a conta nasceu habilitada');
    }

    public function test_registering_does_not_hand_out_a_session(): void
    {
        $this->cadastrar();

        $this->assertGuest();
        $this->assertNull(auth()->user(), 'o cadastro autenticou o visitante');
    }

    /**
     * E a conta recém-criada não entra nem tentando -- a guarda do #277
     * responde por isso, e este teste fixa que os dois lados combinam.
     */
    public function test_the_new_account_cannot_log_in_before_release(): void
    {
        $this->cadastrar();

        $this->from('/login')->post('/login', [
            'email' => 'novo@example.com',
            'password' => 'senha-bem-grande',
        ])->assertRedirect('/login');

        $this->assertGuest();
    }

    /**
     * Habilitada pela organização, entra. Sem este controle, tudo acima
     * passaria igual se o cadastro tivesse virado uma rota que não cria
     * conta nenhuma.
     */
    public function test_the_account_works_once_the_organisation_releases_it(): void
    {
        $this->cadastrar();

        User::where('email', 'novo@example.com')->update(['is_enabled' => true]);

        $this->post('/login', [
            'email' => 'novo@example.com',
            'password' => 'senha-bem-grande',
        ])->assertRedirect('/home');

        $this->assertAuthenticated();
    }

    public function test_the_visitor_is_told_to_wait_for_the_organisation(): void
    {
        $this->cadastrar()->assertSessionHas('success');

        $this->assertStringContainsString(
            'liberada pela organizacao',
            (string) session('success'),
            'a mensagem nao diz que a conta espera liberacao'
        );
    }

    // ---------------------------------------------------------------
    // O throttle, que é o mesmo do /login de propósito.
    // ---------------------------------------------------------------

    /**
     * As duas portas para a mesma tabela de usuários não podem ter limites
     * diferentes: a mais frouxa é a que vale.
     */
    public function test_the_sixth_attempt_in_a_minute_is_refused(): void
    {

        for ($i = 1; $i <= 5; $i++) {
            $this->cadastrar(email: "tentativa{$i}@example.com", extra: ['username' => "tentativa{$i}"])
                ->assertRedirect(route('login'));
        }

        $this->cadastrar(email: 'tentativa6@example.com', extra: ['username' => 'tentativa6'])
            ->assertStatus(429);
    }

    // ---------------------------------------------------------------
    // O $fillable, que só vale porque passou a usar o modelo.
    // ---------------------------------------------------------------

    /**
     * Escalada de privilégio pela porta da frente.
     *
     * `user_type` não está entre os campos validados, e um cadastro que se
     * declarasse `admin` entraria como administrador. Vale dizer o que NÃO
     * protege: `user_type` e `is_enabled` estão no `$fillable` de
     * `Helium\User`, então trocar `DB::table()` pelo modelo não resolve
     * isto por si -- a mutação que passa `$request->all()` por
     * `User::create()` derruba este teste. Quem protege é o array
     * explícito no controller.
     */
    public function test_a_self_registered_account_cannot_choose_its_own_role(): void
    {
        $this->cadastrar(['user_type' => User::TYPE_ADMIN]);

        $user = User::where('email', 'novo@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame(User::TYPE_TEAM, $user->user_type, 'o cadastro escolheu o proprio papel');
    }

    public function test_a_self_registered_account_cannot_enable_itself(): void
    {
        $this->cadastrar(['is_enabled' => 1]);

        $user = User::where('email', 'novo@example.com')->first();

        $this->assertNotNull($user);
        $this->assertFalse((bool) $user->is_enabled, 'o cadastro habilitou a propria conta');
    }

    // ---------------------------------------------------------------
    // A porta que fecha.
    // ---------------------------------------------------------------

    public function test_the_route_does_not_exist_when_registration_is_closed(): void
    {
        config(['registration.open' => false]);

        $this->get('/register')->assertNotFound();
        $this->cadastrar()->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'novo@example.com']);
    }

    /**
     * Ligado é o padrão, porque a rota já existia e é linkada na tela de
     * login: fechar por omissão mudaria o comportamento de quem já usa.
     */
    public function test_registration_is_open_by_default(): void
    {
        $this->assertTrue(config('registration.open'));

        $this->get('/register')->assertSuccessful();
    }

    public function test_the_login_screen_offers_the_link_only_when_open(): void
    {
        $this->get('/login')->assertSee('Cadastre-se');

        config(['registration.open' => false]);

        $resposta = $this->get('/login');
        $resposta->assertDontSee('Cadastre-se');
        $resposta->assertSee('criadas pela organização', false);
    }
}
