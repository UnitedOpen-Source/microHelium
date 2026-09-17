<?php

namespace Tests\Feature\Clics;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\Language;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Score;
use App\Models\Site;
use App\Services\ContestFinalizer;
use Helium\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #195 -- a Contest API da ICPC, fase 1.
 *
 * O que estes testes protegem, em ordem de importancia:
 *
 * 1. A restricao de congelamento, que e normativa: um consumidor PUBLICO nao
 *    pode receber o veredito de um envio da janela de congelamento. Sem
 *    isso, /judgements entrega pela porta dos fundos a classificacao que o
 *    /scoreboard esconde.
 * 2. A integridade referencial, o unico requisito duro desta fase: um
 *    `team_id` que nao aparece em /teams quebra o consumidor, e quebra em
 *    silencio -- uma linha faltando num painel.
 * 3. O formato dos tempos (RELTIME), onde um erro nao da erro: o consumidor
 *    le, interpreta como outra coisa e segue.
 */
class ContestApiTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    private Problem $problem;

    private Answer $yes;

    private Language $language;

    private User $team;

    protected function setUp(): void
    {
        parent::setUp();

        // A 270 minutos de uma prova de 300 com congelamento de 60: dentro
        // da janela, que e onde a restricao normativa vale.
        $this->contest = Contest::factory()->frozen()->create(['is_public' => true]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id, 'name' => 'Sede Central']);
        $this->problem = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'A']);
        // Uma linguagem DESTE contest, e nao a do RunFactory.
        //
        // O factory cria a linguagem por Language::factory(), que cria um
        // contest proprio -- entao o run apontava para uma linguagem de
        // outra prova e /languages nao a listava. Em producao isso nao
        // acontece, mas o teste de integridade referencial pegou na
        // primeira execucao, que e exatamente o trabalho dele: um
        // `language_id` que /languages nao lista quebra o consumidor em
        // silencio.
        $this->language = Language::factory()->create(['contest_id' => $this->contest->id]);
        $this->yes = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'YES', 'name' => 'Accepted', 'is_accepted' => true]);
        Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'NO', 'name' => 'Wrong answer', 'is_accepted' => false]);

        $this->team = $this->createTestUser([
            'fullname' => 'Equipe Alfa',
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
        ]);
    }

    private function submit(int $minute, ?Answer $answer = null): Run
    {
        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $this->team->user_id,
            'problem_id' => $this->problem->id,
            'language_id' => $this->language->id,
            'status' => $answer ? 'judged' : 'pending',
            'answer_id' => $answer?->id,
            'contest_time' => $minute * 60,
            'judged_time' => $answer ? $minute * 60 : null,
        ]);

        if ($answer) {
            Score::updateScore($run);
        }

        return $run;
    }

    private function staff(): User
    {
        return $this->createTestUser(['user_type' => 'judge', 'contest_id' => $this->contest->id]);
    }

    // -- os dois endpoints obrigatorios -------------------------------------

    /** "The only required endpoints are metadata: `api` and `access`." */
    public function test_the_two_required_metadata_endpoints_answer(): void
    {
        $api = $this->getJson('/api/clics')->assertStatus(200)->json();
        $this->assertArrayHasKey('version', $api);

        $this->getJson('/api/clics/access')->assertStatus(200)->assertJsonStructure(['capabilities', 'endpoints']);
    }

    /**
     * O que o endpoint `api` promete tem que ser verdade.
     *
     * Na fase 1 (#195) esta nota dizia que o event feed NAO existia, e havia
     * um teste exigindo 404 naquela rota -- o registro honesto de uma
     * fronteira. O #219 moveu a fronteira: o feed existe. O teste virou o
     * seu inverso em vez de ser apagado, porque a propriedade que importa e
     * a mesma nos dois casos: a nota e a realidade concordam.
     */
    public function test_the_api_endpoint_describes_the_feed_it_actually_has(): void
    {
        $api = $this->getJson('/api/clics')->assertStatus(200)->json();

        $this->assertStringContainsString('event-feed', $api['provider']['notes']);

        $this->get("/api/clics/contests/{$this->contest->id}/event-feed")->assertStatus(200);
    }

    // -- visibilidade de contest (#134) -------------------------------------

    /**
     * A porta que quase nao existiu.
     *
     * Registrar o grupo fora de routes/api.php tira a sessao e o Sanctum --
     * e tirou junto, sem que ninguem pedisse, a regra de visibilidade que
     * todo o resto do sistema aplica. Medido antes de consertar: um
     * visitante anonimo recebia o conjunto de problemas INTEIRO de um
     * contest nao publico que ainda nem tinha comecado.
     *
     * E o cenario que o proprio Contest.php descreve: "The gate matters most
     * before an event opens: is_public defaults to false, and a contest that
     * has not started still has its whole problem set loaded."
     */
    public function test_an_anonymous_reader_cannot_open_an_unannounced_contest(): void
    {
        $secreto = Contest::factory()->notStarted()->create(['is_public' => false]);
        Problem::factory()->create(['contest_id' => $secreto->id, 'name' => 'Problema Sigiloso']);

        foreach (['', '/state', '/problems', '/teams', '/organizations', '/groups', '/languages', '/judgement-types', '/submissions', '/judgements', '/scoreboard', '/awards'] as $suffix) {
            $this->getJson("/api/clics/contests/{$secreto->id}{$suffix}")
                ->assertStatus(404, "GET /contests/{id}{$suffix} abriu um contest nao anunciado");
        }
    }

    /**
     * 404 e nao 403, pela mesma razao ja escrita em
     * Controller::authorizeContestVisibility(): um 403 confirma que o id
     * nomeia um contest de verdade, e um evento nao anunciado e algo que nao
     * se deve confirmar por incremento.
     */
    public function test_an_unannounced_contest_is_not_listed(): void
    {
        Contest::factory()->notStarted()->create(['is_public' => false, 'name' => 'Seletiva Secreta']);

        $nomes = array_column($this->getJson('/api/clics/contests')->assertStatus(200)->json(), 'name');

        $this->assertNotContains('Seletiva Secreta', $nomes);
    }

    /**
     * Controle positivo: a porta nao pode estar simplesmente fechada para
     * todos. Um contest publico continua legivel por qualquer um -- que e o
     * caso de uso inteiro desta API.
     */
    public function test_a_public_contest_stays_readable_by_anyone(): void
    {
        $nomes = array_column($this->getJson('/api/clics/contests')->assertStatus(200)->json(), 'name');

        $this->assertContains($this->contest->name, $nomes);
        $this->getJson("/api/clics/contests/{$this->contest->id}/problems")->assertStatus(200);
    }

    /**
     * E quem COMPETE no contest nao publico o enxerga: a regra e
     * Contest::isVisibleTo(), e nao "publico ou nada".
     */
    public function test_a_team_of_the_contest_sees_its_own_unannounced_contest(): void
    {
        $secreto = Contest::factory()->notStarted()->create(['is_public' => false]);
        $membro = $this->createTestUser(['user_type' => 'team', 'contest_id' => $secreto->id]);

        Sanctum::actingAs($membro);

        $this->getJson("/api/clics/contests/{$secreto->id}/problems")->assertStatus(200);
    }

    public function test_the_jury_sees_an_unannounced_contest(): void
    {
        $secreto = Contest::factory()->notStarted()->create(['is_public' => false]);

        Sanctum::actingAs($this->staff());

        $this->getJson("/api/clics/contests/{$secreto->id}/problems")->assertStatus(200);
    }

    // -- o congelamento, que e normativo ------------------------------------

    public function test_a_public_client_does_not_get_judgements_from_the_freeze_window(): void
    {
        $antes = $this->submit(30, $this->yes);
        $durante = $this->submit(260, $this->yes);

        $ids = array_column($this->getJson("/api/clics/contests/{$this->contest->id}/judgements")->assertStatus(200)->json(), 'submission_id');

        $this->assertContains((string) $antes->id, $ids);
        $this->assertNotContains((string) $durante->id, $ids, '/judgements entregou o veredito de um envio do congelamento');
    }

    /**
     * Os ENVIOS continuam visiveis: a spec esconde o julgamento, e nao a
     * submissao -- e e o que faz o placar poder mostrar uma celula pendente.
     */
    public function test_submissions_from_the_freeze_window_are_still_listed(): void
    {
        $durante = $this->submit(260, $this->yes);

        $ids = array_column($this->getJson("/api/clics/contests/{$this->contest->id}/submissions")->assertStatus(200)->json(), 'id');

        $this->assertContains((string) $durante->id, $ids);
    }

    public function test_the_jury_does_get_the_frozen_judgements(): void
    {
        $durante = $this->submit(260, $this->yes);

        Sanctum::actingAs($this->staff());
        $ids = array_column($this->getJson("/api/clics/contests/{$this->contest->id}/judgements")->assertStatus(200)->json(), 'submission_id');

        $this->assertContains((string) $durante->id, $ids);
    }

    public function test_thawing_releases_the_judgements_to_everyone(): void
    {
        $durante = $this->submit(260, $this->yes);
        $this->contest->update(['unfrozen_at' => now()]);

        $ids = array_column($this->getJson("/api/clics/contests/{$this->contest->id}/judgements")->assertStatus(200)->json(), 'submission_id');

        $this->assertContains((string) $durante->id, $ids);
    }

    public function test_the_public_scoreboard_is_the_frozen_one(): void
    {
        $this->submit(260, $this->yes);

        $board = $this->getJson("/api/clics/contests/{$this->contest->id}/scoreboard")->assertStatus(200)->json();

        $this->assertFalse($board['rows'][0]['problems'][0]['solved']);
        $this->assertSame(1, $board['rows'][0]['problems'][0]['num_pending']);
    }

    // -- /state -------------------------------------------------------------

    /**
     * `frozen` e o INSTANTE, e nao um booleano. A spec e explicita, e um
     * booleano aqui e o erro que o consumidor nao detecta: ele le "truthy" e
     * segue.
     */
    public function test_state_reports_frozen_as_an_instant(): void
    {
        $state = $this->getJson("/api/clics/contests/{$this->contest->id}/state")->assertStatus(200)->json();

        $this->assertIsString($state['frozen']);
        $this->assertNotEmpty($state['started']);
        $this->assertNull($state['thawed']);
        $this->assertNull($state['finalized']);
    }

    public function test_state_follows_the_contest_through_thaw_and_finalize(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(400), 'unfrozen_at' => now()]);
        $this->contest->refresh();
        app(ContestFinalizer::class)->finalize($this->contest, $this->staff());

        $state = $this->getJson("/api/clics/contests/{$this->contest->id}/state")->assertStatus(200)->json();

        $this->assertNotNull($state['ended']);
        $this->assertNotNull($state['thawed']);
        $this->assertNotNull($state['finalized']);
        $this->assertSame($state['finalized'], $state['end_of_updates']);
    }

    /**
     * Um contest sem congelamento configurado nunca congelou, e `frozen` tem
     * que ser null -- nao o instante do fim.
     *
     * A prova TEM que ja ter acabado para este teste valer alguma coisa.
     * Com freeze_time = 0 o corte cai no fim da prova, entao num contest
     * ainda correndo `frozen` sairia null pelo motivo errado (ainda nao
     * chegou la) e o teste passaria sem tocar na guarda. Medido: com o
     * contest congelado do setUp, apagar a guarda nao derrubava nada. E a
     * armadilha de sinal oposto que o #189 fechou no predicado.
     */
    public function test_a_finished_contest_without_a_freeze_never_reports_frozen(): void
    {
        $this->contest->update(['freeze_time' => 0, 'start_time' => now()->subMinutes(400)]);

        $state = $this->getJson("/api/clics/contests/{$this->contest->id}/state")->assertStatus(200)->json();

        $this->assertNotNull($state['ended'], 'o cenario exige uma prova ja terminada');
        $this->assertNull($state['frozen']);
    }

    /**
     * E antes de a janela comecar, `frozen` tambem e null.
     *
     * Sem este caso, o instante do congelamento podia ser publicado desde o
     * primeiro minuto da prova -- e um consumidor que le `frozen` como "esta
     * congelado" esconderia o placar a prova inteira.
     */
    public function test_a_contest_before_its_freeze_window_does_not_report_frozen(): void
    {
        $this->contest->update(['start_time' => now()->subMinutes(10)]);

        $state = $this->getJson("/api/clics/contests/{$this->contest->id}/state")->assertStatus(200)->json();

        $this->assertNotNull($state['started']);
        $this->assertNull($state['frozen']);
    }

    // -- integridade referencial --------------------------------------------

    public function test_every_id_a_submission_references_exists(): void
    {
        $this->submit(30, $this->yes);

        $base = "/api/clics/contests/{$this->contest->id}";
        $teams = array_column($this->getJson("{$base}/teams")->json(), 'id');
        $problems = array_column($this->getJson("{$base}/problems")->json(), 'id');
        $languages = array_column($this->getJson("{$base}/languages")->json(), 'id');
        $groups = array_column($this->getJson("{$base}/groups")->json(), 'id');

        $submissions = $this->getJson("{$base}/submissions")->json();
        $this->assertNotEmpty($submissions, 'sem envios este teste nao prova nada');

        foreach ($submissions as $submission) {
            $this->assertContains($submission['team_id'], $teams);
            $this->assertContains($submission['problem_id'], $problems);
            $this->assertContains($submission['language_id'], $languages);
        }

        foreach ($this->getJson("{$base}/teams")->json() as $team) {
            foreach ($team['group_ids'] as $groupId) {
                $this->assertContains($groupId, $groups);
            }
        }
    }

    public function test_a_judgement_type_exists_for_every_judgement(): void
    {
        $this->submit(30, $this->yes);

        $base = "/api/clics/contests/{$this->contest->id}";
        $types = array_column($this->getJson("{$base}/judgement-types")->json(), 'id');

        Sanctum::actingAs($this->staff());
        $judgements = $this->getJson("{$base}/judgements")->json();
        $this->assertNotEmpty($judgements, 'sem julgamentos este teste nao prova nada');

        foreach ($judgements as $judgement) {
            $this->assertContains($judgement['judgement_type_id'], $types);
        }
    }

    /**
     * O BOCA chama de YES/NO o que a spec chama de AC/WA. Sem a tabela de
     * traducao o consumidor recebe "NO" onde espera "WA" e trata como
     * veredito desconhecido.
     */
    public function test_boca_verdicts_are_translated_to_the_standard_ids(): void
    {
        $types = array_column(
            $this->getJson("/api/clics/contests/{$this->contest->id}/judgement-types")->json(),
            'id'
        );

        $this->assertContains('AC', $types);
        $this->assertContains('WA', $types);
        $this->assertNotContains('YES', $types);
        $this->assertNotContains('NO', $types);
    }

    /**
     * `CS` -- o veredito que a propria infraestrutura produz ao desistir
     * (#45) -- e `JE` no padrao, que e exatamente o que o #202 exige que nao
     * exista para poder finalizar.
     */
    public function test_the_infrastructure_failure_verdict_maps_to_judging_error(): void
    {
        Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'CS', 'name' => 'Contest system error']);

        $types = array_column(
            $this->getJson("/api/clics/contests/{$this->contest->id}/judgement-types")->json(),
            'id'
        );

        $this->assertContains('JE', $types);
    }

    public function test_an_organization_a_team_points_at_is_listed(): void
    {
        $org = Organization::create(['name' => 'Universidade Exemplo']);
        OrganizationMembership::create([
            'organization_id' => $org->id,
            'user_id' => $this->team->user_id,
            'role' => OrganizationMembership::ROLE_EDITOR,
        ]);

        $base = "/api/clics/contests/{$this->contest->id}";
        $orgIds = array_column($this->getJson("{$base}/organizations")->json(), 'id');
        $teams = $this->getJson("{$base}/teams")->json();

        $this->assertSame((string) $org->id, $teams[0]['organization_id']);
        $this->assertContains((string) $org->id, $orgIds);
    }

    /**
     * Uma equipe sem vinculo sai com organization_id null, que a spec
     * permite -- e nao com um id que /organizations nao lista.
     */
    public function test_a_team_without_an_organization_reports_null(): void
    {
        $base = "/api/clics/contests/{$this->contest->id}";

        $this->assertNull($this->getJson("{$base}/teams")->json()[0]['organization_id']);
        $this->assertSame([], $this->getJson("{$base}/organizations")->json());
    }

    // -- formato ------------------------------------------------------------

    /**
     * RELTIME e HH:MM:SS.mmm. Um erro aqui nao da erro: o consumidor le,
     * interpreta como outra coisa e segue.
     */
    public function test_durations_use_the_reltime_format(): void
    {
        $contest = $this->getJson("/api/clics/contests/{$this->contest->id}")->assertStatus(200)->json();

        $this->assertSame('5:00:00.000', $contest['duration'], '300 minutos sao cinco horas');
        $this->assertSame('1:00:00.000', $contest['scoreboard_freeze_duration']);

        $this->submit(90, $this->yes);
        $submission = $this->getJson("/api/clics/contests/{$this->contest->id}/submissions")->json()[0];

        $this->assertSame('1:30:00.000', $submission['contest_time']);
    }

    /**
     * IDs sao STRINGS na spec, sempre -- inclusive quando a chave e numerica
     * aqui. Um consumidor que compare com === falharia em todas.
     */
    public function test_every_id_is_a_string(): void
    {
        $this->submit(30, $this->yes);
        $base = "/api/clics/contests/{$this->contest->id}";

        foreach (['problems', 'teams', 'groups', 'languages', 'submissions'] as $collection) {
            $objects = $this->getJson("{$base}/{$collection}")->json();
            $this->assertNotEmpty($objects, "{$collection} veio vazio; o teste nao provaria nada");

            foreach ($objects as $object) {
                $this->assertIsString($object['id'], "{$collection}: id numerico");
            }
        }
    }

    // -- premiacao e CORS ---------------------------------------------------

    /**
     * A premiacao so existe depois de finalizada. Antes disso a lista de
     * medalhas e a classificacao final dita de outro jeito, e publica-la num
     * endpoint anonimo durante o congelamento entregaria exatamente o que o
     * congelamento esconde -- o mesmo raciocinio do #202.
     */
    public function test_awards_are_not_published_before_the_contest_is_finalized(): void
    {
        $this->getJson("/api/clics/contests/{$this->contest->id}/awards")->assertStatus(404);
    }

    public function test_awards_appear_once_finalized(): void
    {
        $this->submit(30, $this->yes);
        $this->contest->update(['start_time' => now()->subMinutes(400), 'unfrozen_at' => now()]);
        $this->contest->refresh();
        app(ContestFinalizer::class)->finalize($this->contest, $this->staff());

        $awards = $this->getJson("/api/clics/contests/{$this->contest->id}/awards")->assertStatus(200)->json();

        $this->assertContains('winner', array_column($awards, 'id'));
    }

    /**
     * Sem este cabecalho a ferramenta que consome isto -- uma pagina servida
     * de outro lugar -- nao le nada, e a falha aparece so no dia do evento,
     * no console de alguem.
     */
    public function test_cors_is_open_as_the_spec_requires(): void
    {
        $this->getJson('/api/clics')->assertStatus(200)->assertHeader('Access-Control-Allow-Origin', '*');
    }

    /**
     * Um token DE VERDADE, pelo cabecalho Authorization.
     *
     * Este teste existe separado de test_the_jury_does_get_the_frozen_judgements
     * porque aquele usa Sanctum::actingAs(), que injeta o usuario direto e
     * NAO passa pelo resolvedor do middleware. Uma mutacao provou a lacuna:
     * apagando o setUserResolver inteiro a suite continuava verde, e o
     * caminho que um shadow da propria organizacao realmente usa -- mandar
     * um Bearer -- nao estava coberto por teste nenhum.
     */
    public function test_a_real_bearer_token_from_the_jury_gets_the_unfrozen_view(): void
    {
        $durante = $this->submit(260, $this->yes);
        $token = $this->staff()->createToken('shadow')->plainTextToken;

        $ids = array_column(
            $this->getJson("/api/clics/contests/{$this->contest->id}/judgements", [
                'Authorization' => 'Bearer '.$token,
            ])->assertStatus(200)->json(),
            'submission_id'
        );

        $this->assertContains((string) $durante->id, $ids);
    }

    /**
     * E um token de EQUIPE continua vendo a versao congelada: autenticar nao
     * e o mesmo que ser da organizacao (#134).
     */
    public function test_a_real_bearer_token_from_a_team_still_gets_the_frozen_view(): void
    {
        $durante = $this->submit(260, $this->yes);
        $token = $this->team->createToken('equipe')->plainTextToken;

        $ids = array_column(
            $this->getJson("/api/clics/contests/{$this->contest->id}/judgements", [
                'Authorization' => 'Bearer '.$token,
            ])->assertStatus(200)->json(),
            'submission_id'
        );

        $this->assertNotContains((string) $durante->id, $ids);
    }

    /**
     * Um token invalido nao pode derrubar a requisicao: a leitura e anonima
     * por desenho, e o que a autenticacao muda e se a resposta vem congelada
     * ou nao.
     */
    public function test_an_invalid_token_reads_as_an_anonymous_visitor(): void
    {
        $this->submit(260, $this->yes);

        $response = $this->getJson("/api/clics/contests/{$this->contest->id}/judgements", [
            'Authorization' => 'Bearer isto-nao-e-um-token',
        ])->assertStatus(200);

        $this->assertSame([], $response->json(), 'um token invalido deveria ler como visitante, nao falhar nem virar banca');
    }

    // -- o gate de verificacao (#274) ---------------------------------------

    /**
     * Issue #274 -- a rota e ANONIMA, e o veredito ainda nao foi liberado.
     *
     * O congelamento ja era respeitado aqui, com o argumento de que um
     * consumidor publico que recebesse o veredito de um envio do
     * congelamento "entregaria a classificacao pela porta dos fundos". O
     * mesmo vale para o veredito retido -- e sem conta nenhuma.
     */
    public function test_a_public_client_does_not_get_a_withheld_verdict(): void
    {
        $this->contest->update(['verification_required' => true]);

        $this->submit(10, $this->yes);   // julgado, nao verificado

        $response = $this->getJson("/api/clics/contests/{$this->contest->id}/judgements")
            ->assertStatus(200);

        $this->assertSame([], $response->json(), 'veredito nao verificado saiu para consumidor anonimo');
    }

    public function test_the_jury_does_get_the_withheld_verdict(): void
    {
        $this->contest->update(['verification_required' => true]);

        $this->submit(10, $this->yes);

        $response = $this->actingAs($this->staff())
            ->getJson("/api/clics/contests/{$this->contest->id}/judgements")
            ->assertStatus(200);

        $this->assertCount(1, $response->json(), 'a banca precisa ver o veredito retido -- verificar exige enxergar');
    }

    /**
     * O outro lado, para a guarda nao passar por esconder tudo.
     */
    public function test_once_released_the_verdict_reaches_the_public_client(): void
    {
        $this->contest->update(['verification_required' => true]);

        $run = $this->submit(10, $this->yes);
        $run->update(['verified_at' => now(), 'verified_by' => $this->staff()->user_id]);

        $response = $this->getJson("/api/clics/contests/{$this->contest->id}/judgements")
            ->assertStatus(200);

        $this->assertCount(1, $response->json(), 'veredito liberado tem de aparecer');
    }

    /**
     * Sem verificacao manual configurada nada muda -- o caminho comum.
     */
    public function test_without_manual_verification_the_verdict_is_public_at_once(): void
    {
        $this->submit(10, $this->yes);

        $response = $this->getJson("/api/clics/contests/{$this->contest->id}/judgements")
            ->assertStatus(200);

        $this->assertCount(1, $response->json());
    }
}
