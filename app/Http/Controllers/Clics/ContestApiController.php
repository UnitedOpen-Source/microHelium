<?php

namespace App\Http\Controllers\Clics;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\Run;
use App\Services\Clics\ClicsPresenter;
use App\Services\Clics\EventFeedBuilder;
use App\Services\Clics\EventFeedStream;
use App\Services\Clics\FreezeWindow;
use App\Services\Clics\TeamAffiliation;
use App\Services\ContestClock;
use App\Services\ScoreboardTeams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Issue #195 -- a Contest API da ICPC, fase 1 (so leitura, sem event feed).
 *
 * Registrada fora de routes/api.php de proposito. Aquele arquivo documenta
 * um contrato proprio sob auth:sanctum; isto implementa uma ESPECIFICACAO
 * EXTERNA e versionada, cujo contrato normativo mora em
 * ccs-specs.icpc.io e nao aqui. O mesmo raciocinio ja escrito para o grupo
 * /api/frontend/*.
 *
 * O que a fase 1 NAO faz: habilitar o resolver. O resolver le o event feed,
 * que e a fase 2. Ver docs/specs/195-contest-api.md.
 *
 * ACESSO: leitura anonima, MAS atras da porta do #134.
 *
 * Isto quase saiu errado. Registrar o grupo fora de routes/api.php tira a
 * sessao e o Sanctum -- e tirou junto, sem que ninguem pedisse, a regra de
 * visibilidade de contest que todo o resto do sistema aplica. Medido antes
 * de consertar: um visitante anonimo recebia o conjunto de problemas
 * inteiro de um contest NAO PUBLICO QUE AINDA NAO TINHA COMECADO --
 * exatamente a divulgacao que o #134 existe para impedir, e que o proprio
 * Contest.php descreve: "The gate matters most before an event opens:
 * is_public defaults to false, and a contest that has not started still has
 * its whole problem set loaded."
 *
 * O corte do congelamento NAO cobria isso: ele esconde vereditos, e a
 * pergunta aqui e outra -- se esta prova pode ser vista. Duas portas
 * diferentes, e so uma estava no lugar.
 */
class ContestApiController extends Controller
{
    /**
     * O que o `access` declara -- e, por isso, o que esta API PROMETE.
     *
     * Issue #332. A spec faz do `access` o mecanismo normativo de
     * descoberta ("Types of endpoints"):
     *
     *   "The access endpoint specifies which other endpoints are offered by
     *    the API. That is, any endpoints and their properties listed in
     *    `access` must be provided (possibly with a `null` value when the
     *    property is optional), and only these endpoints and properties."
     *
     * Ate aqui respondiamos `properties: []` nos onze endpoints -- lido ao
     * pe da letra, "ofereco onze colecoes e nenhuma delas tem propriedade
     * nenhuma" -- e nao declaravamos `state` nem `event-feed`, que estao em
     * routes/clics.php e respondem 200. Um resolver que descobrisse as
     * capacidades pelo `access` (que e o que a spec manda fazer) concluia
     * que o microHelium NAO TEM event feed, justamente a parte que o ICPC
     * Tools chama de "the only part of the Contest API that is strictly
     * required".
     *
     * Esta lista nao pode ser decorativa, e a suite a compara com as chaves
     * que cada endpoint REALMENTE devolve (ConformidadeClicsTest). Acrescentar
     * um campo no presenter sem acrescentar aqui fica vermelho, e vice-versa.
     *
     * `minItems: 1` em `access.json` e o motivo de nao haver lista vazia.
     *
     * @var array<string, list<string>>
     */
    private const ENDPOINTS_OFERECIDOS = [
        'contest' => ['id', 'name', 'formal_name', 'start_time', 'duration', 'scoreboard_freeze_duration', 'scoreboard_type', 'penalty_time'],
        'problems' => ['id', 'label', 'name', 'ordinal', 'test_data_count', 'rgb', 'color'],
        'teams' => ['id', 'name', 'label', 'display_name', 'group_ids', 'organization_id', 'icpc_id'],
        'organizations' => ['id', 'icpc_id', 'name', 'formal_name', 'country'],
        'groups' => ['id', 'icpc_id', 'name', 'type'],
        'languages' => ['id', 'name', 'entry_point_required', 'extensions'],
        'judgement-types' => ['id', 'name', 'penalty', 'solved'],
        'submissions' => ['id', 'language_id', 'problem_id', 'team_id', 'time', 'contest_time', 'files'],
        'judgements' => ['id', 'submission_id', 'judgement_type_id', 'start_time', 'start_contest_time', 'end_contest_time', 'end_time'],
        'state' => ['started', 'ended', 'frozen', 'thawed', 'finalized', 'end_of_updates'],
        'scoreboard' => ['time', 'contest_time', 'state', 'rows'],
        'awards' => ['id', 'citation', 'team_ids'],
        // As quatro propriedades da LINHA do feed, que e o objeto que este
        // endpoint devolve: `event-feed.json` lista exatamente `type`, `id`,
        // `data` e `token` (#330).
        'event-feed' => ['type', 'id', 'data', 'token'],
    ];

    public function __construct(private ClicsPresenter $presenter) {}

    /**
     * O endpoint `api`, um dos dois unicos obrigatorios da spec.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'version' => '2023-06',
            'version_url' => 'https://ccs-specs.icpc.io/2023-06/contest_api',
            'name' => 'microHelium',
            // Dito em voz alta, e nao omitido: um consumidor que espere o
            // event feed precisa descobrir que ele nao existe AQUI, e nao
            // depois de um GET que responde 404 no meio de uma cerimonia.
            'provider' => [
                'name' => 'microHelium',
                'notes' => 'Somente leitura, com /event-feed em NDJSON (issue #219). Sem escrita pela Contest API.',
            ],
        ]);
    }

    /**
     * O endpoint `access`, o outro obrigatorio: o que ESTE cliente pode ver.
     */
    public function access(Request $request): JsonResponse
    {
        $staff = $this->isStaff($request);

        return response()->json([
            // Vazio e a verdade: esta API e SOMENTE LEITURA. As capacidades
            // do enum de `common.json#/capabilities` sao todas de escrita
            // (`contest_start`, `team_submit`, `proxy_clar`, ...), e nenhuma
            // delas existe aqui.
            'capabilities' => [],
            'endpoints' => array_map(
                fn (string $type, array $properties) => ['type' => $type, 'properties' => $properties],
                array_keys(self::ENDPOINTS_OFERECIDOS),
                array_values(self::ENDPOINTS_OFERECIDOS)
            ),
            // Nao e decoracao: o mesmo GET devolve conteudos diferentes para
            // um visitante e para a banca durante o congelamento, e sem isto
            // o cliente nao tem como saber qual dos dois recebeu.
            'frozen_view' => ! $staff,
        ]);
    }

    public function contests(Request $request): JsonResponse
    {
        return response()->json(
            Contest::query()
                ->competition()
                ->visibleTo($request->user())
                ->get()
                ->map(fn (Contest $contest) => $this->presenter->contest($contest))
                ->values()
                ->all()
        );
    }

    public function contest(Contest $contest): JsonResponse
    {
        $this->authorizeContestVisibility($contest);

        return response()->json($this->presenter->contest($contest));
    }

    public function state(Contest $contest): JsonResponse
    {
        $this->authorizeContestVisibility($contest);

        return response()->json($this->presenter->state($contest));
    }

    public function problems(Contest $contest): JsonResponse
    {
        $this->authorizeContestVisibility($contest);

        return response()->json($this->presenter->problems($contest));
    }

    public function teams(Contest $contest): JsonResponse
    {
        $this->authorizeContestVisibility($contest);
        TeamAffiliation::flush();

        return response()->json($this->presenter->teams(ScoreboardTeams::forContest($contest)));
    }

    public function organizations(Contest $contest): JsonResponse
    {
        $this->authorizeContestVisibility($contest);
        TeamAffiliation::flush();

        return response()->json($this->presenter->organizations(ScoreboardTeams::forContest($contest)));
    }

    public function groups(Contest $contest): JsonResponse
    {
        $this->authorizeContestVisibility($contest);

        return response()->json($this->presenter->groups($contest));
    }

    public function languages(Contest $contest): JsonResponse
    {
        $this->authorizeContestVisibility($contest);

        return response()->json($this->presenter->languages($contest));
    }

    public function judgementTypes(Contest $contest): JsonResponse
    {
        $this->authorizeContestVisibility($contest);

        return response()->json($this->presenter->judgementTypes($contest));
    }

    /**
     * Os ENVIOS continuam visiveis durante o congelamento.
     *
     * A spec esconde o julgamento, e nao a submissao -- e e o que faz o
     * placar congelado poder mostrar "?3" numa celula (#211). Esconder a
     * existencia do envio seria um congelamento diferente e pior.
     */
    public function submissions(Request $request, Contest $contest): JsonResponse
    {
        $this->authorizeContestVisibility($contest);

        return response()->json($this->presenter->submissions($contest, $this->runs($contest)));
    }

    /**
     * Os JULGAMENTOS da janela de congelamento ficam de fora.
     *
     * Restricao normativa da spec, e a mesma regra que o #211 aplica ao
     * placar: um consumidor publico que recebesse o veredito de um envio do
     * congelamento saberia, pelo /judgements, o que o /scoreboard esconde --
     * e entregaria a classificacao pela porta dos fundos.
     */
    public function judgements(Request $request, Contest $contest, FreezeWindow $freeze): JsonResponse
    {
        $this->authorizeContestVisibility($contest);

        $runs = $this->runs($contest)->filter(fn (Run $run) => $run->answer_id !== null);

        // Issue #274 -- o gate de verificacao, que faltava aqui.
        //
        // O congelamento ja era respeitado logo abaixo, com o argumento
        // escrito no docblock: um consumidor publico que recebesse o
        // veredito de um envio do congelamento "entregaria a classificacao
        // pela porta dos fundos". O mesmo argumento vale, palavra por
        // palavra, para o veredito que a banca ainda nao liberou -- e esta
        // rota e ANONIMA, entao o consumidor nem precisa de conta.
        //
        // `isStaff()` ja e a regra do #138 (Run::viewerSeesWithheldVerdicts),
        // usada duas linhas adiante para o freeze. Uma definicao, duas
        // perguntas.
        if (! $this->isStaff($request)) {
            $runs = $runs->filter(fn (Run $run) => ! $run->isVerdictWithheld());
        }

        // Issue #319 -- o corte da SEDE de cada run, e nao um numero so.
        //
        // Era `cutoffSeconds($contest)` aplicado a lista inteira. Numa prova
        // de 300/60 com uma sede de 240/60, essa sede congela no minuto 180
        // dela e o corte global ficava no 240: os julgamentos da ULTIMA HORA
        // daquela sede saiam por aqui enquanto ela ainda submetia -- e o
        // /scoreboard, corrigido no #341, ja os escondia. A mesma resposta
        // pelas duas portas e o ponto inteiro de `FreezeWindow`.
        if ($this->frozenFor($request, $contest)) {
            $runs = $runs->filter(fn (Run $run) => ! $freeze->covers($contest, $run));
        }

        return response()->json($this->presenter->judgements($contest, $runs->values()));
    }

    public function scoreboard(Request $request, Contest $contest): JsonResponse
    {
        $this->authorizeContestVisibility($contest);

        return response()->json($this->presenter->scoreboard($contest, $this->frozenFor($request, $contest)));
    }

    /**
     * A premiacao (#202).
     *
     * So depois de finalizada. Antes disso a lista de medalhas e a
     * classificacao final dita de outro jeito, e publica-la num endpoint
     * anonimo durante o congelamento seria entregar exatamente o que o
     * congelamento esconde -- o mesmo raciocinio que o #202 usa para
     * impedir finalizar com o placar congelado.
     */
    public function awards(Contest $contest): JsonResponse
    {
        $this->authorizeContestVisibility($contest);

        if (! $contest->isFinalized()) {
            return response()->json([], 404);
        }

        return response()->json($this->presenter->awards($contest));
    }

    /**
     * Issue #219 -- o event feed, em NDJSON.
     *
     * E o que o resolver le: "the only part of the Contest API that is
     * strictly required is the event feed and any file references that the
     * feed refers to". A fase 1 (#195) entregou o REST e dizia, no proprio
     * endpoint `api`, que o feed nao existia -- agora existe.
     *
     * Uma linha JSON por evento, sem virgulas e sem colchetes: o consumidor
     * le linha a linha enquanto a prova acontece, e um array JSON so estaria
     * completo no fim.
     *
     * `since_token` retoma. Um cliente que caiu volta dizendo ate onde leu e
     * recebe exatamente o que veio depois -- e por isso a fotografia inicial
     * NAO e repetida: ele ja tem os objetos estaticos.
     *
     * Issue #328 -- e a conexao NAO FECHA. "The feed does not terminate
     * under normal circumstances" (secao Event feed). O laco, o keep-alive e
     * as tres saidas moram em EventFeedStream; aqui fica so o que e HTTP.
     */
    public function eventFeed(Request $request, Contest $contest, EventFeedBuilder $feed, EventFeedStream $stream): StreamedResponse|JsonResponse
    {
        $this->authorizeContestVisibility($contest);

        $unrestricted = $this->isStaff($request);

        // Issue #330 -- `since_id` nao e suportado, e a spec diz o que
        // responder: "If the token is invalid, the time passed is too large
        // [...] or the server does not support this parameter, the request
        // will fail with a 400 error" (secao Reconnection). O `check-api.sh`
        // do proprio ICPC lista `400:event-feed?since_id=999999` entre os
        // casos OBRIGATORIOS de falha.
        //
        // Responder 200 era pior do que parecia: o ICPC Tools so manda
        // `since_id` quando nao conseguiu ler `token` -- e ele nao conseguia
        // por causa do `op` (corrigido nesta mesma leva). Com 200, ele
        // rebaixava o feed inteiro a cada reconexao, para sempre.
        if ($request->query->has('since_id')) {
            return $this->feedRecusada(
                'since_id nao e suportado; use since_token, que vem no campo "token" de cada linha do feed.'
            );
        }

        $sinceToken = null;

        if ($request->query->has('since_token')) {
            $bruto = $request->query('since_token');
            $numerico = is_string($bruto) && preg_match('/^\d+$/', $bruto) === 1;

            // "The client is guaranteed to either get a 400 error or
            // receive at least all changes since the token." Um token
            // adiante do fim do log nao tem "todas as mudancas desde" para
            // entregar -- e respondiamos 200 com corpo VAZIO, que e o pior
            // dos mundos: o resolver fica com o modelo que tinha e acha que
            // esta em dia.
            if (! $numerico || (int) $bruto > $feed->highestToken($contest)) {
                return $this->feedRecusada('since_token invalido para esta prova: '.(string) $bruto);
            }

            $sinceToken = (int) $bruto;
        }

        return response()->stream(function () use ($contest, $stream, $sinceToken, $unrestricted) {
            $stream->run(
                $contest,
                $sinceToken,
                $unrestricted,
                fn (?array $line) => $line === null ? $this->emitKeepAlive() : $this->emit($line)
            );
        }, 200, [
            // A spec pede NDJSON; `application/x-ndjson` e o tipo que os
            // consumidores esperam. `no-cache` porque um feed cacheado e um
            // feed que conta o passado como se fosse o presente.
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache',
            'Access-Control-Allow-Origin' => '*',
            // Sem isto o nginx entre o consumidor e nos guarda a resposta
            // ate o fim, e "streaming" vira "tudo de uma vez no final" -- a
            // falha aparece so em producao, com proxy no meio.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function emit(array $line): void
    {
        echo json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n";

        // Empurra a linha para o cliente em vez de deixa-la no buffer do
        // PHP. Sem isto o feed entrega tudo junto quando a resposta fecha, o
        // que passa em teste (o teste le o corpo inteiro) e falha em uso (o
        // resolver espera a primeira linha).
        if (ob_get_level() > 0) {
            @ob_flush();
        }

        @flush();
    }

    /**
     * O keep-alive da spec: um NEWLINE, e nada mais.
     *
     * "to ensure keep alive a newline must be sent if there has been no
     * event within 120 seconds" (secao Event feed). Tem de ser newline puro
     * e nao um evento vazio -- o consumidor de NDJSON pula linha em branco,
     * e um objeto JSON inventado seria uma mudanca que nao aconteceu.
     */
    private function emitKeepAlive(): void
    {
        echo "\n";

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        @flush();
    }

    /**
     * O 400 que a spec pede na retomada invalida (#330).
     *
     * Existe um caminho de recuperacao no ICPC Tools que depende EXATAMENTE
     * deste codigo para disparar ("Contest has been reset! Throwing out
     * cache and reconnecting"). Sem o 400 ele nunca roda, e um contest
     * recriado deixa o cliente com um modelo incoerente e nenhum sinal.
     */
    private function feedRecusada(string $motivo): JsonResponse
    {
        return response()->json(['code' => 400, 'message' => $motivo], 400);
    }

    /**
     * @return Collection<int, Run>
     */
    private function runs(Contest $contest)
    {
        return Run::where('contest_id', $contest->id)
            // `language` junto (#333): o `language_id` da Contest API agora e
            // o identificador CLICS, que mora no slug da linguagem. Sem o
            // eager load, /submissions viraria um N+1 por envio.
            ->with(['answer:id,short_name,is_accepted', 'language:id,extension'])
            ->orderBy('contest_time')
            ->orderBy('id')
            ->get();
    }

    private function frozenFor(Request $request, Contest $contest): bool
    {
        if ($this->isStaff($request)) {
            return false;
        }

        // Issue #276 -- a janela da SEDE de quem pergunta.
        //
        // O consumidor tipico desta API e anonimo -- painel, resolver -- e
        // cai na resposta conservadora de `isFrozenForAnyone()`, que e a
        // unica defensavel aqui: entregar o quadro descongelado porque UMA
        // sede ja acabou publicaria o que as outras ainda escondem, e esta e
        // justamente a restricao normativa que a fase 1 do #195 protege.
        $viewer = $request->user();
        $siteId = $viewer?->site_id !== null ? (int) $viewer->site_id : null;
        $clock = app(ContestClock::class);

        return $siteId !== null
            ? $clock->isFrozenFor($contest, $siteId)
            : $clock->isFrozenForAnyone($contest);
    }

    /**
     * A mesma regra nomeada em Run::viewerSeesWithheldVerdicts(), pela
     * mesma razao do #211: "quem e da organizacao" nao pode existir em duas
     * versoes.
     */
    private function isStaff(Request $request): bool
    {
        return Run::viewerSeesWithheldVerdicts($request->user());
    }
}
