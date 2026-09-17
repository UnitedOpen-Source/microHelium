<?php

namespace Tests\Feature;

use App\Models\Contest;
use App\Models\Problem;
use Helium\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Issue #254 -- as duas superficies publicas que ignoravam `isVisibleTo()`.
 *
 * O #134 poe a regra de visibilidade no modelo, e o docblock dela diz por
 * que: "one rule with one home, so the listing and the detail endpoint
 * cannot drift apart the way #135 found them drifted on the web side".
 * Cinco controllers da API perguntam. Dois lugares nao perguntavam:
 *
 *  - `GET /api/contest/current`, que devolve nome e cronograma completo do
 *    contest ativo -- `is_public` ou nao. O #148 achou, nao mexeu de
 *    proposito e explicou: `routes/api.php` nao tem `statefulApi()`, entao
 *    requisicao de navegador para `/api/*` nao carrega sessao, e filtrar por
 *    `is_public` apagaria o relogio de toda prova privada (que e o padrao da
 *    coluna).
 *  - `ScoreboardController::resolveContest()`, que roda numa rota PUBLICA e
 *    resolve o contest ativo com a mesma query sem filtro. A pagina imprime
 *    `short_name` e `name` de cada problema -- exatamente "a lista de
 *    problemas" que o #135 fechou para o visitante anonimo no resto da web.
 *
 * O terceiro teste daqui e o que impede a correcao errada. Filtrar sem
 * resolver a sessao deixaria os dois primeiros verdes e apagaria o relogio
 * de quem esta competindo -- teste verde contra mecanismo que nao funciona,
 * que e o defeito recorrente deste repositorio. Por isso ele loga pelo
 * caminho real, `POST /login`, e nao por `actingAs()`: `actingAs()` injeta o
 * usuario direto no resolver e passaria mesmo sem sessao nenhuma.
 */
class ContestVisibilityOnPublicSurfacesTest extends TestCase
{
    private function privateContest(): Contest
    {
        return Contest::factory()->create([
            'name' => 'Seletiva interna',
            'is_active' => true,
            'is_practice' => false,
            'is_public' => false,
        ]);
    }

    private function member(Contest $contest): User
    {
        return User::factory()->create([
            'email' => 'time@example.com',
            'password' => bcrypt('password123'),
            'contest_id' => $contest->id,
        ]);
    }

    private function loginThroughTheRealForm(User $user): void
    {
        $this->post('/login', ['email' => $user->email, 'password' => 'password123']);
        $this->assertAuthenticated();

        // Medido: nem isto basta. O cliente de teste reaproveita a mesma
        // instancia da aplicacao entre requisicoes de um mesmo teste, e o
        // usuario continua resolvido mesmo com os guards esquecidos -- este
        // teste passa com e sem o middleware `web` na rota. Por isso o
        // mecanismo esta pinado em
        // test_the_timer_route_actually_runs_the_session_middleware, e nao
        // aqui: afirmar que este teste prova a sessao seria a mentira que
        // este repositorio ja catalogou oito vezes.
        $this->app['auth']->forgetGuards();
    }

    public function test_an_anonymous_visitor_does_not_get_a_private_contest_from_the_timer_endpoint(): void
    {
        $contest = $this->privateContest();

        $body = $this->getJson('/api/contest/current')->assertStatus(200)->json();

        $this->assertNull($body['name'] ?? null, 'o nome da prova privada saiu para um anonimo');
        $this->assertNull($body['start_time'] ?? null, 'o cronograma da prova privada saiu para um anonimo');
        $this->assertNotSame($contest->name, $body['name'] ?? null);
    }

    public function test_an_anonymous_visitor_still_gets_a_public_contest_from_the_timer_endpoint(): void
    {
        $contest = Contest::factory()->create([
            'name' => 'Regional aberta',
            'is_active' => true,
            'is_practice' => false,
            'is_public' => true,
        ]);

        $body = $this->getJson('/api/contest/current')->assertStatus(200)->json();

        $this->assertSame($contest->name, $body['name'] ?? null, 'o relogio parou de funcionar para prova publica');
        $this->assertNotNull($body['start_time'] ?? null);
    }

    public function test_someone_competing_in_a_private_contest_still_gets_the_clock(): void
    {
        $contest = $this->privateContest();
        $this->loginThroughTheRealForm($this->member($contest));

        $body = $this->getJson('/api/contest/current')->assertStatus(200)->json();

        $this->assertSame($contest->name, $body['name'] ?? null, 'o relogio sumiu para quem esta competindo: a sessao nao chega na rota');
        $this->assertNotNull($body['start_time'] ?? null);
    }

    public function test_an_anonymous_visitor_does_not_get_a_private_contests_problem_list_from_the_scoreboard(): void
    {
        $contest = $this->privateContest();
        $problem = Problem::factory()->create([
            'contest_id' => $contest->id,
            'name' => 'Enunciado reservado',
            'short_name' => 'ZZ',
        ]);

        $html = $this->get('/scoreboard')->assertStatus(200)->getContent();

        $this->assertStringNotContainsString($problem->name, $html, 'o nome do problema da prova privada saiu para um anonimo');
        $this->assertStringNotContainsString('>'.$problem->short_name.'<', $html, 'o short_name do problema da prova privada saiu para um anonimo');
    }

    public function test_someone_competing_still_sees_the_problem_list_on_the_scoreboard(): void
    {
        $contest = $this->privateContest();
        $problem = Problem::factory()->create([
            'contest_id' => $contest->id,
            'name' => 'Enunciado reservado',
            'short_name' => 'ZZ',
        ]);
        $this->loginThroughTheRealForm($this->member($contest));

        $html = $this->get('/scoreboard')->assertStatus(200)->getContent();

        $this->assertStringContainsString($problem->name, $html, 'o placar escondeu o problema de quem esta competindo');
    }

    /**
     * O mecanismo, ja que o comportamento nao da para medir daqui.
     *
     * `routes/api.php` nao tem `statefulApi()`: medido com `route:list`, o
     * middleware desta rota sem a linha adicionada e `['api']` e nada mais
     * -- nenhuma sessao. Num navegador, `auth()->user()` seria null mesmo
     * para quem acabou de logar, e o filtro do #254 trataria o competidor
     * como estranho, apagando o relogio de toda prova privada. Era isso que
     * o #148 previu ao nao mexer.
     *
     * Um teste de comportamento nao consegue mostrar isso (ver o comentario
     * em loginThroughTheRealForm), entao o que se pina aqui e a presenca do
     * middleware. Guarda estrutural e mais fraca que guarda de
     * comportamento, e esta escrito que e o caso.
     */
    public function test_the_timer_route_actually_runs_the_session_middleware(): void
    {
        $rota = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/contest/current');

        $this->assertNotNull($rota, 'a rota do relogio mudou de caminho');

        $this->assertContains(
            'web',
            $rota->gatherMiddleware(),
            'a rota do relogio perdeu a sessao: sem ela auth()->user() e null no navegador e o filtro do #254 apaga o relogio de quem esta competindo'
        );
    }
}
