<?php

namespace Tests\Feature;

use App\Models\Contest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Issue #277 -- conta desabilitada nao entra pelo login web.
 *
 * `auth()->attempt()` nao olha `is_enabled`: o provider padrao do Eloquent
 * so compara credenciais. O buraco ja estava documentado em
 * `Api\TokenController`, que fecha o lado dele e diz, sobre este:
 *
 *   "auth()->attempt() does not look at is_enabled, so the web login has
 *    the same hole; that one is #47's, not this route's, but this route is
 *    not going to be the second copy of it."
 *
 * Importa para o #47: conta gerenciada de menor de idade nasce
 * DESABILITADA de proposito, para so ser usavel depois da ativacao. Sem
 * esta guarda, ela entra assim que alguem souber a senha.
 */
class LoginDisabledAccountTest extends TestCase
{
    use RefreshDatabase;

    private function conta(bool $habilitada): void
    {
        $contest = Contest::factory()->create();

        $this->createTestUser([
            'email' => 'time@example.com',
            'password' => Hash::make('senha-correta'),
            'contest_id' => $contest->id,
            'is_enabled' => $habilitada,
        ]);
    }

    private function entrar(string $senha = 'senha-correta'): TestResponse
    {
        return $this->from('/login')->post('/login', [
            'email' => 'time@example.com',
            'password' => $senha,
        ]);
    }

    public function test_a_disabled_account_does_not_get_a_session(): void
    {
        $this->conta(habilitada: false);

        $this->entrar()->assertRedirect('/login');

        // assertGuest() recebe nome de guard, nao mensagem -- daí a
        // asserção explícita para o caso de falhar.
        $this->assertGuest();
        $this->assertNull(auth()->user(), 'a conta desabilitada recebeu sessao');
    }

    public function test_the_refusal_says_what_is_wrong(): void
    {
        $this->conta(habilitada: false);

        $this->entrar()->assertSessionHasErrors('email');
    }

    /**
     * O outro lado, para a guarda nao passar por recusar todo mundo.
     */
    public function test_an_enabled_account_still_gets_in(): void
    {
        $this->conta(habilitada: true);

        $this->entrar()->assertRedirect('/home');

        $this->assertAuthenticated();
    }

    /**
     * A senha errada continua sendo senha errada, e nao vira "desabilitada".
     *
     * A checagem roda DEPOIS do attempt() de proposito: antes, qualquer um
     * descobriria quais contas estao desabilitadas sem ter credencial
     * nenhuma. Este teste fixa essa ordem.
     */
    public function test_a_wrong_password_on_a_disabled_account_reads_as_wrong_password(): void
    {
        $this->conta(habilitada: false);

        $this->entrar(senha: 'senha-errada')->assertRedirect('/login');

        $this->assertGuest();

        $this->assertDatabaseMissing('contest_logs', [
            'message' => 'Login bloqueado: conta desabilitada',
        ]);
    }

    /**
     * "Quem tentou entrar com conta desabilitada, e quando" e pergunta que
     * se faz depois de um incidente.
     */
    public function test_the_blocked_attempt_is_recorded(): void
    {
        $this->conta(habilitada: false);

        $this->entrar();

        $this->assertDatabaseHas('contest_logs', [
            'message' => 'Login bloqueado: conta desabilitada',
        ]);
    }

    /**
     * Issue #47 -- o caso que motiva tudo isto.
     *
     * A conta gerenciada e provisionada desabilitada, e so a ativacao a
     * torna usavel. Antes desta guarda, saber a senha bastava.
     */
    public function test_a_managed_account_cannot_be_used_before_activation(): void
    {
        $contest = Contest::factory()->create();

        $this->createTestUser([
            'email' => 'menor@example.com',
            'password' => Hash::make('senha-provisoria'),
            'contest_id' => $contest->id,
            'is_enabled' => false,
            'profile_visibility' => 'private',
        ]);

        $this->from('/login')->post('/login', [
            'email' => 'menor@example.com',
            'password' => 'senha-provisoria',
        ])->assertRedirect('/login');

        $this->assertGuest();
    }
}
