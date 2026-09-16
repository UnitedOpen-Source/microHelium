<?php

namespace App\Http\Controllers\Clics;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\Run;
use App\Services\Clics\ClicsPresenter;
use App\Services\Clics\OrganizationMembershipLookup;
use App\Services\FrozenScoreboard;
use App\Services\ScoreboardTeams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
                'notes' => 'Fase 1: somente leitura. Sem /event-feed -- o resolver do ICPC Tools nao funciona contra esta instalacao.',
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

    public function contests(): JsonResponse
    {
        return response()->json(
            Contest::query()->competition()->get()->map(fn (Contest $contest) => $this->presenter->contest($contest))->all()
        );
    }

    public function contest(Contest $contest): JsonResponse
    {
        return response()->json($this->presenter->contest($contest));
    }

    public function state(Contest $contest): JsonResponse
    {
        return response()->json($this->presenter->state($contest));
    }

    public function problems(Contest $contest): JsonResponse
    {
        return response()->json($this->presenter->problems($contest));
    }

    public function teams(Contest $contest): JsonResponse
    {
        OrganizationMembershipLookup::flush();

        return response()->json($this->presenter->teams(ScoreboardTeams::forContest($contest)));
    }

    public function organizations(Contest $contest): JsonResponse
    {
        OrganizationMembershipLookup::flush();

        return response()->json($this->presenter->organizations(ScoreboardTeams::forContest($contest)));
    }

    public function groups(Contest $contest): JsonResponse
    {
        return response()->json($this->presenter->groups($contest));
    }

    public function languages(Contest $contest): JsonResponse
    {
        return response()->json($this->presenter->languages($contest));
    }

    public function judgementTypes(Contest $contest): JsonResponse
    {
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
        $runs = $this->runs($contest)->filter(fn (Run $run) => $run->answer_id !== null);

        if ($this->frozenFor($request, $contest)) {
            $cutoff = FrozenScoreboard::cutoffSeconds($contest);
            $runs = $runs->filter(fn (Run $run) => (int) $run->contest_time < $cutoff);
        }

        return response()->json($this->presenter->judgements($contest, $runs->values()));
    }

    public function scoreboard(Request $request, Contest $contest): JsonResponse
    {
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
        if (! $contest->isFinalized()) {
            return response()->json([], 404);
        }

        return response()->json($this->presenter->awards($contest));
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
        return $contest->isFrozen() && ! $this->isStaff($request);
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
