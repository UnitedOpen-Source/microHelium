<?php

namespace Tests\Feature\Clics;

use App\Models\Answer;
use App\Models\Contest;
use App\Models\ContestEvent;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use App\Models\Score;
use App\Models\Site;
use App\Services\Clics\ContestEventRecorder;
use App\Services\ContestFinalizer;
use Helium\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Issue #219 -- o event feed, que e o que o resolver le.
 *
 * "the only part of the Contest API that is strictly required is the event
 * feed and any file references that the feed refers to." A fase 1 (#195)
 * entregou o REST e DIZIA, no proprio endpoint `api`, que o feed nao
 * existia.
 *
 * O que estes testes protegem, em ordem de importancia:
 *
 * 1. A retomada por `since_token`. Um feed que reinicia do zero quando o
 *    processo cai e um feed que o resolver nao consegue usar.
 * 2. O congelamento num STREAM, que e mais dificil que no REST: la basta
 *    filtrar uma lista; aqui um evento omitido some para sempre, entao os
 *    julgamentos da janela precisam aparecer NO DESCONGELAMENTO, na ordem
 *    certa.
 * 3. O formato: uma linha JSON por evento, sem virgulas e sem colchetes.
 */
class EventFeedTest extends TestCase
{
    private Contest $contest;

    private Site $site;

    private Problem $problem;

    private Language $language;

    private Answer $yes;

    private User $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contest = Contest::factory()->frozen()->create(['is_public' => true]);
        $this->site = Site::factory()->create(['contest_id' => $this->contest->id]);
        $this->problem = Problem::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'A']);
        $this->language = Language::factory()->create(['contest_id' => $this->contest->id]);
        $this->yes = Answer::factory()->create(['contest_id' => $this->contest->id, 'short_name' => 'YES', 'is_accepted' => true]);
        $this->team = $this->createTestUser([
            'fullname' => 'Equipe Alfa',
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
        ]);
    }

    private function submitAt(int $minute): Run
    {
        $run = Run::factory()->create([
            'contest_id' => $this->contest->id,
            'site_id' => $this->site->id,
            'user_id' => $this->team->user_id,
            'problem_id' => $this->problem->id,
            'language_id' => $this->language->id,
        ])->fresh();

        $run->update(['contest_time' => $minute * 60]);

        app(ContestEventRecorder::class)->submissionCreated($run->fresh());

        return $run->fresh();
    }

    private function judge(Run $run): Run
    {
        $run->update(['status' => 'judged', 'answer_id' => $this->yes->id, 'judged_time' => $run->contest_time]);
        Score::updateScore($run->fresh());
        app(ContestEventRecorder::class)->judgementRecorded($run->fresh());

        return $run->fresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function feed(array $query = [], ?User $as = null): array
    {
        if ($as !== null) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($as);
        }

        $url = "/api/clics/contests/{$this->contest->id}/event-feed";

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        $body = $this->get($url)->assertStatus(200)->streamedContent();

        $lines = [];

        foreach (explode("\n", trim($body)) as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, "linha do feed nao e JSON valido: {$line}");
            $lines[] = $decoded;
        }

        return $lines;
    }

    private function staff(): User
    {
        return $this->createTestUser(['user_type' => 'judge', 'contest_id' => $this->contest->id]);
    }

    // -- formato ------------------------------------------------------------

    /**
     * NDJSON: uma linha por evento, sem virgulas e sem colchetes. Um array
     * JSON so estaria completo no fim, e o consumidor le enquanto a prova
     * acontece.
     */
    public function test_the_feed_is_newline_delimited_json(): void
    {
        $response = $this->get("/api/clics/contests/{$this->contest->id}/event-feed")->assertStatus(200);

        $this->assertStringContainsString('application/x-ndjson', $response->headers->get('Content-Type'));

        $body = trim($response->streamedContent());

        $this->assertStringStartsNotWith('[', $body, 'o feed nao pode ser um array JSON');
        $this->assertGreaterThan(1, substr_count($body, "\n"));
    }

    /**
     * A fotografia abre o feed: os objetos estaticos vem do estado atual, e
     * nao de um log -- guardar log para dados que nao mudam no meio de uma
     * prova seria pagar caro pelo caso que nao acontece.
     */
    public function test_the_feed_opens_with_a_snapshot_of_the_static_objects(): void
    {
        $tipos = array_column($this->feed(), 'type');

        foreach (['contest', 'judgement-types', 'languages', 'groups', 'teams', 'problems', 'state'] as $esperado) {
            $this->assertContains($esperado, $tipos, "a fotografia nao trouxe {$esperado}");
        }
    }

    /**
     * A fotografia sai SEM token: um token e uma posicao no log, e a
     * fotografia nao esta no log. Dar a ela um token inventado faria um
     * cliente pedir "depois desse" e pular eventos reais abaixo dele.
     */
    public function test_snapshot_lines_carry_no_token(): void
    {
        foreach ($this->feed() as $line) {
            if (in_array($line['type'], ['contest', 'teams', 'problems', 'languages', 'groups', 'judgement-types'], true)) {
                $this->assertArrayNotHasKey('token', $line, "{$line['type']} da fotografia veio com token");
            }
        }
    }

    // -- o formato de 2023-06, e nao o de 2020-03 (#330) ---------------------

    /**
     * Issue #330 -- nenhuma linha pode trazer `op`.
     *
     * Nao e purismo de schema. O NDJSONFeedParser do ICPC Tools BIFURCA
     * pela presenca da propriedade:
     *
     *     String op = obj.getString("op");
     *     if (op != null) parseOldFormat(...); else parseNewFormat(...);
     *
     * e o `parseOldFormat` nunca le `token`. Enquanto emitiamos `op` em
     * todas as linhas, `getLastToken()` devolvia null para sempre e a
     * retomada por `since_token` -- projetada, documentada e testada na
     * #219 -- era codigo morto: nenhum cliente de verdade chegava a
     * envia-la.
     */
    public function test_no_line_carries_the_op_property_that_2023_06_removed(): void
    {
        $this->judge($this->submitAt(10));

        $linhas = $this->feed();

        $this->assertNotEmpty($linhas);

        foreach ($linhas as $linha) {
            $this->assertArrayNotHasKey(
                'op',
                $linha,
                "a linha de {$linha['type']} traz \"op\": o ICPC Tools cai no parser antigo e para de ler o token"
            );
        }
    }

    /**
     * Issue #330 -- singleton sai com `id: null`.
     *
     * "The id of the object that changed, or null for the entire
     * collection/singleton", e "If `type` is `contest`, then `id` must be
     * null" (Notification format). `state` e singleton pela mesma secao
     * ("`api`, `access`, `account`, `state`, `scoreboard`, and `event-feed`
     * are singular nouns").
     */
    public function test_the_contest_and_state_lines_carry_a_null_id(): void
    {
        foreach ($this->feed() as $linha) {
            if (in_array($linha['type'], ['contest', 'state'], true)) {
                $this->assertArrayHasKey('id', $linha, "a linha de {$linha['type']} precisa TER a chave id");
                $this->assertNull($linha['id'], "o singleton {$linha['type']} saiu com id nao-nulo");
            }
        }
    }

    /**
     * E o resto continua com id: senao a assercao acima passaria num feed
     * que perdeu todos os ids.
     */
    public function test_the_collections_still_carry_the_object_id(): void
    {
        $run = $this->submitAt(10);

        $envios = array_values(array_filter($this->feed(), fn ($l) => $l['type'] === 'submissions'));

        $this->assertNotEmpty($envios);
        $this->assertSame((string) $run->id, $envios[0]['id']);
    }

    // -- a retomada invalida, que a spec manda recusar (#330) ---------------

    /**
     * "If the token is invalid, the time passed is too large [...] or the
     * server does not support this parameter, the request will fail with a
     * 400 error." (Reconnection)
     *
     * Os tres casos medidos na auditoria respondiam 200 -- e o
     * `?since_token=999999` era o pior deles: corpo VAZIO e bem-sucedido. O
     * resolver fica com o modelo que tinha e acha que esta em dia.
     */
    public function test_a_non_numeric_since_token_is_refused_with_400(): void
    {
        $this->get("/api/clics/contests/{$this->contest->id}/event-feed?since_token=abc")
            ->assertStatus(400);
    }

    public function test_a_since_token_beyond_the_end_of_the_log_is_refused_with_400(): void
    {
        $this->judge($this->submitAt(10));

        $ultimo = (int) ContestEvent::where('contest_id', $this->contest->id)->max('id');

        $this->get("/api/clics/contests/{$this->contest->id}/event-feed?since_token=".($ultimo + 1))
            ->assertStatus(400);

        // O controle positivo: o ULTIMO token e valido. Sem esta metade, um
        // endpoint que respondesse 400 a qualquer since_token passaria.
        $this->get("/api/clics/contests/{$this->contest->id}/event-feed?since_token={$ultimo}")
            ->assertStatus(200);
    }

    /**
     * `since_id` e o parametro que o ICPC Tools manda quando nao conseguiu
     * ler o token, e o valor e o id do OBJETO -- nao uma posicao no log.
     * O `check-api.sh` do ICPC lista `400:event-feed?since_id=999999` entre
     * os casos obrigatorios de falha.
     */
    public function test_the_legacy_since_id_parameter_is_refused_with_400(): void
    {
        $this->judge($this->submitAt(10));

        $this->get("/api/clics/contests/{$this->contest->id}/event-feed?since_id=1")
            ->assertStatus(400);
    }

    // -- retomada, que e o requisito duro -----------------------------------

    public function test_events_carry_a_token(): void
    {
        $this->submitAt(30);

        $submissions = array_values(array_filter($this->feed(), fn ($l) => $l['type'] === 'submissions'));

        $this->assertNotEmpty($submissions);
        $this->assertArrayHasKey('token', $submissions[0]);
    }

    /**
     * O requisito duro. Um cliente que caiu volta dizendo ate onde leu e
     * recebe exatamente o que veio depois.
     */
    public function test_since_token_returns_only_what_came_after(): void
    {
        $primeiro = $this->submitAt(10);
        $segundo = $this->submitAt(20);

        $tokenDoPrimeiro = (string) ContestEvent::where('object_id', (string) $primeiro->id)->value('id');

        $ids = array_column(
            array_filter($this->feed(['since_token' => $tokenDoPrimeiro]), fn ($l) => $l['type'] === 'submissions'),
            'id'
        );

        $this->assertSame([(string) $segundo->id], $ids);
    }

    /**
     * E a fotografia NAO se repete na retomada: o cliente ja tem os objetos
     * estaticos, e repeti-los a cada reconexao faria um resolver redesenhar
     * a tela inteira toda vez que a rede oscilasse.
     */
    public function test_resuming_does_not_repeat_the_snapshot(): void
    {
        $this->submitAt(10);

        $tipos = array_column($this->feed(['since_token' => 0]), 'type');

        $this->assertNotContains('teams', $tipos);
        $this->assertNotContains('problems', $tipos);
    }

    // -- o congelamento num stream ------------------------------------------

    /**
     * A parte que o REST nao precisava resolver: la basta filtrar uma lista,
     * aqui um evento omitido some para sempre.
     */
    public function test_a_judgement_from_the_freeze_window_is_held_back_from_the_public(): void
    {
        $antes = $this->judge($this->submitAt(30));
        $durante = $this->judge($this->submitAt(260));

        $julgados = array_column(
            array_filter($this->feed(), fn ($l) => $l['type'] === 'judgements'),
            'id'
        );

        $this->assertContains((string) $antes->id, $julgados);
        $this->assertNotContains((string) $durante->id, $julgados, 'o feed publico entregou um julgamento do congelamento');
    }

    /**
     * O ENVIO continua aparecendo: a spec esconde o julgamento e nao a
     * submissao, e e o que faz o placar poder mostrar celula pendente
     * (#211). Um feed que escondesse o envio deixaria o cliente sem saber
     * nem que a equipe tentou.
     */
    public function test_the_submission_from_the_freeze_window_is_still_announced(): void
    {
        $durante = $this->submitAt(260);

        $enviados = array_column(
            array_filter($this->feed(), fn ($l) => $l['type'] === 'submissions'),
            'id'
        );

        $this->assertContains((string) $durante->id, $enviados);
    }

    public function test_the_jury_sees_the_held_back_judgement_immediately(): void
    {
        $durante = $this->judge($this->submitAt(260));

        $julgados = array_column(
            array_filter($this->feed([], $this->staff()), fn ($l) => $l['type'] === 'judgements'),
            'id'
        );

        $this->assertContains((string) $durante->id, $julgados);
    }

    /**
     * E no descongelamento eles saem -- na ordem do token, que e a ordem em
     * que aconteceram.
     */
    public function test_thawing_releases_the_held_back_judgements_in_order(): void
    {
        $primeiro = $this->judge($this->submitAt(250));
        $segundo = $this->judge($this->submitAt(260));

        $this->contest->update(['unfrozen_at' => now()]);

        $julgados = array_column(
            array_filter($this->feed(), fn ($l) => $l['type'] === 'judgements'),
            'id'
        );

        $this->assertSame([(string) $primeiro->id, (string) $segundo->id], $julgados);
    }

    /**
     * O token que o feed publico entrega nao pode ser o de um evento
     * escondido: o cliente pediria "depois de N" e nunca receberia o que
     * estava em N.
     */
    public function test_the_public_never_receives_a_token_it_cannot_resume_from(): void
    {
        $this->judge($this->submitAt(30));
        $escondido = $this->judge($this->submitAt(260));

        $tokenEscondido = (string) ContestEvent::where('type', 'judgements')
            ->where('object_id', (string) $escondido->id)
            ->value('id');

        $tokens = array_column(array_filter($this->feed(), fn ($l) => isset($l['token'])), 'token');

        $this->assertNotContains($tokenEscondido, $tokens);
    }

    // -- fim das atualizacoes -----------------------------------------------

    /**
     * `end_of_updates` so depois de finalizar (#202) -- e so ai "nao vem mais
     * nada" e verdade.
     */
    public function test_end_of_updates_appears_only_after_the_contest_is_finalized(): void
    {
        // UMA linha de estado antes de finalizar: a da fotografia.
        //
        // Contar as linhas, e nao so olhar o campo: `end_of_updates` sai de
        // ClicsPresenter::state() e ja e null enquanto a prova nao foi
        // finalizada, entao uma assercao sobre o CAMPO passa com ou sem a
        // guarda. O que a guarda decide e se uma linha de fechamento e
        // acrescentada -- e um feed que repete `state` no fim de toda
        // consulta e ruido. Uma mutacao mostrou isso.
        $estados = array_values(array_filter($this->feed(), fn ($l) => $l['type'] === 'state'));

        $this->assertCount(1, $estados, 'antes de finalizar nao ha linha de fechamento');
        $this->assertNull($estados[0]['data']['end_of_updates']);

        $this->contest->update(['start_time' => now()->subMinutes(400), 'unfrozen_at' => now()]);
        $this->contest->refresh();
        app(ContestFinalizer::class)->finalize($this->contest, $this->staff());

        $estados = array_values(array_filter($this->feed(), fn ($l) => $l['type'] === 'state'));
        $ultimo = end($estados);

        $this->assertGreaterThan(1, count($estados), 'finalizar tem que acrescentar a linha de fechamento');
        $this->assertNotNull($ultimo['data']['end_of_updates']);
    }

    // -- as portas ----------------------------------------------------------

    /**
     * A mesma porta do #134 que o #195 quase deixou aberta: leitura anonima
     * nao e leitura livre.
     */
    public function test_an_unannounced_contest_has_no_feed(): void
    {
        $secreto = Contest::factory()->notStarted()->create(['is_public' => false]);

        $this->get("/api/clics/contests/{$secreto->id}/event-feed")->assertStatus(404);
    }

    public function test_the_api_endpoint_no_longer_says_the_feed_is_missing(): void
    {
        $api = $this->getJson('/api/clics')->assertStatus(200)->json();

        $this->assertStringContainsString('event-feed', $api['provider']['notes']);
        $this->assertStringNotContainsString('nao funciona', $api['provider']['notes']);
    }

    // -- o gate de verificacao (#274) ---------------------------------------

    /**
     * Issue #274 -- o feed grava o evento UMA vez e o reproduz depois.
     *
     * Por isso nao da para "esconder na leitura" como o /judgements faz: o
     * que for gravado sai para o consumidor anonimo. A supressao acontece na
     * gravacao, e a liberacao e que emite -- ver o teste seguinte, que e o
     * que impede esta guarda de virar "o julgamento sumiu para sempre".
     */
    public function test_a_withheld_verdict_does_not_enter_the_feed(): void
    {
        $this->contest->update(['verification_required' => true]);

        $this->judge($this->submitAt(10));

        $julgamentos = array_values(array_filter(
            $this->feed(),
            fn (array $linha) => ($linha['type'] ?? null) === 'judgements'
        ));

        $this->assertSame([], $julgamentos, 'veredito nao verificado entrou no feed');
    }

    /**
     * A outra metade: liberar o veredito o coloca no feed.
     *
     * Sem isto a supressao acima seria pior que o defeito -- o resolver
     * nunca veria aquele julgamento.
     */
    public function test_releasing_the_verdict_puts_the_judgement_in_the_feed(): void
    {
        $this->contest->update(['verification_required' => true]);

        $run = $this->judge($this->submitAt(10));

        $this->actingAs($this->staff())
            ->post("/judge/runs/{$run->id}/verify", ['comment' => 'conferido'])
            ->assertRedirect();

        $julgamentos = array_values(array_filter(
            $this->feed(),
            fn (array $linha) => ($linha['type'] ?? null) === 'judgements'
        ));

        $this->assertCount(1, $julgamentos, 'o veredito liberado precisa aparecer no feed');
    }

    /**
     * Sem verificacao manual configurada, nada muda.
     */
    public function test_without_manual_verification_the_judgement_enters_at_once(): void
    {
        $this->judge($this->submitAt(10));

        $julgamentos = array_values(array_filter(
            $this->feed(),
            fn (array $linha) => ($linha['type'] ?? null) === 'judgements'
        ));

        $this->assertCount(1, $julgamentos);
    }
}
