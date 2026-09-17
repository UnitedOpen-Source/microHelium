<?php

namespace App\Http\Controllers;

use App\Models\ContestLog;
use Helium\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Issue #275 -- o auto-cadastro deixa de entregar sessão.
 *
 * O que existia era um par de closures em `routes/web.php`, e cada um dos
 * quatro problemas era verificável:
 *
 *   1. sem throttle -- `POST /login` tinha `ThrottleRequests:5,1`,
 *      `POST /register` tinha apenas `web`;
 *   2. `DB::table()` direto, contornando o `$fillable` do modelo e qualquer
 *      regra que ele carregue;
 *   3. `is_enabled => true` -- a conta nascia ativa, sem convite e sem
 *      ativação, enquanto as contas gerenciadas do #47 nascem
 *      DESABILITADAS de propósito;
 *   4. `auth()->loginUsingId()` -- sessão autenticada sem ninguém da
 *      organização ter decidido isso.
 *
 * O SRS descreve, em F02, apenas o cadastro administrado (RF-F02-007) e a
 * conta gerenciada com ativação (RF-F02-008). Esta rota não aparece em
 * requisito nenhum: existia comportamento que nada autorizava.
 *
 * ## A decisão
 *
 * A rota FICA, e ligada por padrão: ela é linkada na tela de login e tem
 * suíte E2E própria, então removê-la seria mudar o produto e não consertar
 * um defeito. O que sai é o caminho para sessão: a conta nasce desabilitada
 * e o cadastro não autentica. Habilitar continua sendo decisão da
 * organização, em `/backend/users`.
 *
 * `config('registration.open')` permite fechar a porta numa instalação que
 * só aceita contas criadas pela organização -- o caso da Maratona.
 */
class RegistrationController extends Controller
{
    public function create(): View
    {
        $this->abortWhenClosed();

        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->abortWhenClosed();

        $validated = $request->validate([
            'fullname' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // O que protege aqui e o array EXPLICITO, e nao o `$fillable`.
        //
        // Vale escrever porque a conclusao natural e a errada: `user_type` e
        // `is_enabled` ESTAO no `$fillable` de `Helium\User`, entao passar
        // `$request->all()` por `User::create()` aceitaria os dois -- uma
        // mutacao mostrou exatamente isso, com o cadastro se declarando
        // admin e habilitado. Trocar `DB::table()` pelo modelo nao e a
        // guarda; a guarda e nomear os campos.
        //
        // O modelo entra por outro motivo: `DB::table()->insertGetId()`
        // ignora casts, eventos e valores padrao do modelo, e um dia alguem
        // vai acrescentar um deles esperando que todo caminho de criacao o
        // respeite.
        $user = User::create([
            'fullname' => $validated['fullname'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'user_type' => User::TYPE_TEAM,
            // O ponto inteiro da issue.
            //
            // A conta nasce DESABILITADA, como as contas gerenciadas do #47,
            // e por isso `LoginDisabledAccountTest` e o middleware do #277
            // ja garantem que ela nao entra: nao ha uma segunda guarda aqui
            // para manter em sincronia com aquelas.
            'is_enabled' => false,
        ]);

        // E nao ha `auth()->loginUsingId()`.
        //
        // Era o que tornava a rota um caminho para sessao autenticada sem
        // decisao de ninguem. Mesmo com a conta desabilitada, logar aqui
        // seria pedir ao middleware do #277 para desfazer, na requisicao
        // seguinte, o que esta linha acabou de fazer -- e uma sessao que
        // existe por um instante e uma sessao que existe.
        $this->record($user);

        return redirect()->route('login')->with(
            'success',
            'Cadastro recebido. A conta sera liberada pela organizacao do evento antes do primeiro acesso.'
        );
    }

    /**
     * Fechada, a rota não existe -- 404, e não 403.
     *
     * 403 confirmaria que a instalação tem auto-cadastro e o desligou, o que
     * é informação que quem pergunta não precisa ter. O link na tela de
     * login desaparece junto, para não oferecer uma porta que não abre.
     */
    private function abortWhenClosed(): void
    {
        if (! config('registration.open', true)) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * "Quem se cadastrou sozinho, e quando" é a primeira pergunta de quem
     * vai liberar a conta.
     *
     * Sem prova não há registro: `contest_logs.contest_id` é obrigatório, e
     * uma conta de auto-cadastro nasce sem contest e sem sede por desenho --
     * é a organização que vincula. A tela que lê esses registros filtra por
     * prova de qualquer forma.
     */
    private function record(User $user): void
    {
        $contestId = $user->contest_id ?? $user->site?->contest_id;

        if ($contestId === null) {
            return;
        }

        ContestLog::info((int) $contestId, 'Auto-cadastro recebido, conta aguardando liberacao', [
            'user_id' => $user->user_id,
        ]);
    }
}
