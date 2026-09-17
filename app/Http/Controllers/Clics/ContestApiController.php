<?php

namespace App\Http\Controllers\Clics;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\Run;
use App\Services\ContestClock;
use App\Services\Clics\ClicsPresenter;
use App\Services\Clics\EventFeedBuilder;
use App\Services\Clics\OrganizationMembershipLookup;
use App\Services\FrozenScoreboard;
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
            'capabilities' => [],
            'endpoints' => array_map(fn (string $type) => ['type' => $type, 'properties' => []], [
                'contest', 'problems', 'teams', 'organizations', 'groups',
                'languages', 'judgement-types', 'submissions', 'judgements',
                'scoreboard', 'awards',
            ]),
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
        OrganizationMembershipLookup::flush();

        return response()->json($this->presenter->teams(ScoreboardTeams::forContest($contest)));
    }

    public function organizations(Contest $contest): JsonResponse
    {
        $this->authorizeContestVisibility($contest);
        OrganizationMembershipLookup::flush();

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
    public function judgements(Request $request, Contest $contest): JsonResponse
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

        if ($this->frozenFor($request, $contest)) {
            $cutoff = FrozenScoreboard::cutoffSeconds($contest);
            $runs = $runs->filter(fn (Run $run) => (int) $run->contest_time < $cutoff);
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
     */
    public function eventFeed(Request $request, Contest $contest, EventFeedBuilder $feed): StreamedResponse
    {
        $this->authorizeContestVisibility($contest);

        $unrestricted = $this->isStaff($request);
        $sinceToken = $request->query('since_token');
        $sinceToken = is_numeric($sinceToken) ? (int) $sinceToken : null;

        return response()->stream(function () use ($contest, $feed, $sinceToken, $unrestricted) {
            // A fotografia so quando o cliente esta comecando do zero.
            if ($sinceToken === null) {
                foreach ($feed->snapshot($contest) as $line) {
                    $this->emit($line);
                }
            }

            foreach ($feed->since($contest, $sinceToken, $unrestricted) as $line) {
                $this->emit($line);
            }

            if (($end = $feed->endOfUpdates($contest)) !== null) {
                $this->emit($end);
            }
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
     * @return Collection<int, Run>
     */
    private function runs(Contest $contest)
    {
        return Run::where('contest_id', $contest->id)
            ->with('answer:id,short_name,is_accepted')
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
