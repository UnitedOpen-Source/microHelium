<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\ContestLog;
use Helium\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #277, partes 2 e 3.
 *
 * A parte 1 (o login web) foi fechada no PR #280, e ela responde apenas
 * "quem pode ENTRAR". Faltavam as outras duas: desabilitar uma conta derruba
 * quem ja esta dentro, e invalida o token ja emitido?
 *
 * Ate aqui a resposta era nao para as duas.
 * `Backend\UserController::update()` gravava `is_enabled = false` e mais
 * nada. Desabilitar uma conta durante um incidente e a acao obvia de quem
 * opera, e ela nao derrubava coisa nenhuma.
 *
 * Cada teste daqui desabilita a conta DEPOIS de autenticar, porque e esse o
 * caso: nao adianta recusar credencial que ja foi aceita.
 */
class DisabledAccountRevalidationTest extends TestCase
{
    use RefreshDatabase;

    private function conta(): User
    {
        $contest = Contest::factory()->create();

        return $this->createTestUser([
            'email' => 'time@example.com',
            'password' => Hash::make('senha-correta'),
            'contest_id' => $contest->id,
            'is_enabled' => true,
        ]);
    }

    // ---------------------------------------------------------------
    // Parte 2 -- a sessao aberta.
    // ---------------------------------------------------------------

    public function test_disabling_an_account_ends_the_session_already_open(): void
    {
        $user = $this->conta();

        $this->actingAs($user)->get('/home')->assertSuccessful();

        $user->update(['is_enabled' => false]);

        $this->actingAs($user)->get('/home')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull(auth()->user(), 'a sessao sobreviveu ao desabilitar');
    }

    public function test_the_refusal_says_what_is_wrong(): void
    {
        $user = $this->conta();
        $user->update(['is_enabled' => false]);

        $this->actingAs($user)->get('/home')->assertSessionHasErrors('email');
    }

    /**
     * O outro lado, para a guarda nao valer por derrubar todo mundo.
     */
    public function test_an_enabled_account_keeps_its_session(): void
    {
        $user = $this->conta();

        $this->actingAs($user)->get('/home')->assertSuccessful();
        $this->assertAuthenticated();
    }

    // ---------------------------------------------------------------
    // Parte 3 -- o token ja emitido.
    // ---------------------------------------------------------------

    public function test_disabling_an_account_stops_a_token_already_issued(): void
    {
        $user = $this->conta();
        Sanctum::actingAs($user);

        $this->getJson('/api/contests')->assertSuccessful();

        $user->update(['is_enabled' => false]);

        $this->getJson('/api/contests')
            ->assertStatus(403)
            ->assertJson(['message' => 'Conta desabilitada.']);
    }

    public function test_an_enabled_token_still_works(): void
    {
        $user = $this->conta();
        Sanctum::actingAs($user);

        $this->getJson('/api/contests')->assertSuccessful();
    }

    // ---------------------------------------------------------------
    // A Contest API (#195): leitura anonima por desenho, mas quem
    // apresenta token e staff -- e staff ve veredito retido (#274).
    // ---------------------------------------------------------------

    public function test_a_disabled_judge_is_turned_away_from_the_contest_api(): void
    {
        $contest = Contest::factory()->create([
            'is_active' => true,
            'is_public' => true,
            'start_time' => now()->subHour(),
        ]);

        $judge = $this->createTestUser([
            'email' => 'juiz@example.com',
            'password' => Hash::make('x'),
            'user_type' => User::TYPE_JUDGE,
            'contest_id' => $contest->id,
            'is_enabled' => true,
        ]);

        Sanctum::actingAs($judge);

        // Habilitado, entra -- senao o 403 abaixo valeria por "juiz nao ve
        // esta API", que nao e o que esta sendo verificado.
        $this->getJson("/api/clics/contests/{$contest->id}")->assertSuccessful();

        $judge->update(['is_enabled' => false]);

        $this->getJson("/api/clics/contests/{$contest->id}")->assertStatus(403);
    }

    /**
     * E a leitura anonima, que e o desenho daquela API, segue intacta: o
     * middleware nao faz nada quando nao ha usuario.
     *
     * Sem este teste, "403 para o juiz desabilitado" passaria igual se o
     * middleware tivesse quebrado a API inteira.
     */
    public function test_anonymous_reads_on_the_contest_api_are_untouched(): void
    {
        $contest = Contest::factory()->create([
            'is_active' => true,
            'is_public' => true,
            'start_time' => now()->subHour(),
        ]);

        $this->getJson("/api/clics/contests/{$contest->id}")->assertSuccessful();
    }

    // ---------------------------------------------------------------
    // O registro.
    // ---------------------------------------------------------------

    public function test_the_ended_access_is_recorded(): void
    {
        $user = $this->conta();
        $user->update(['is_enabled' => false]);

        $this->actingAs($user)->get('/home');

        $this->assertDatabaseHas('contest_logs', [
            'message' => 'Acesso encerrado: conta desabilitada',
        ]);
    }

    /**
     * Uma conta sem prova recebe a recusa, e nao um 500.
     *
     * `contest_logs.contest_id` e obrigatorio, e admin nao tem prova nem
     * sede. Passar null a `ContestLog::warning()` e TypeError -- era o que
     * o login do PR #280 fazia, e o que a suite de Treino Livre pegou aqui:
     * o administrador desabilitado recebia erro de servidor no lugar da
     * recusa, que e a pior das duas respostas, porque parece defeito do
     * sistema e nao decisao de quem opera.
     */
    public function test_an_account_with_no_contest_is_refused_and_not_crashed(): void
    {
        $admin = $this->createTestUser([
            'email' => 'admin@example.com',
            'password' => Hash::make('x'),
            'user_type' => User::TYPE_ADMIN,
            'contest_id' => null,
            'site_id' => null,
            'is_enabled' => true,
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/contests')->assertSuccessful();

        $admin->update(['is_enabled' => false]);

        $this->getJson('/api/contests')
            ->assertStatus(403)
            ->assertJson(['message' => 'Conta desabilitada.']);
    }

    /**
     * O mesmo pelo login web, que e onde o defeito nasceu (#280).
     */
    public function test_an_account_with_no_contest_is_refused_at_login_too(): void
    {
        $this->createTestUser([
            'email' => 'admin2@example.com',
            'password' => Hash::make('senha-correta'),
            'user_type' => User::TYPE_ADMIN,
            'contest_id' => null,
            'site_id' => null,
            'is_enabled' => false,
        ]);

        $this->from('/login')->post('/login', [
            'email' => 'admin2@example.com',
            'password' => 'senha-correta',
        ])->assertRedirect('/login');

        $this->assertGuest();
    }

    /**
     * Um cliente de API em laco apresenta o mesmo token desabilitado a cada
     * poucos segundos. Sem limite, `contest_logs` receberia uma linha
     * identica por requisicao ate o disco acabar -- e o registro que existe
     * para ser lido depois de um incidente viraria justamente o que
     * atrapalha ler.
     */
    public function test_a_client_in_a_loop_does_not_flood_the_log(): void
    {
        $user = $this->conta();
        Sanctum::actingAs($user);
        $user->update(['is_enabled' => false]);

        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/contests')->assertStatus(403);
        }

        $this->assertSame(1, ContestLog::query()
            ->where('message', 'Acesso encerrado: conta desabilitada')
            ->count(), 'cada requisicao virou uma linha de log');
    }
}
