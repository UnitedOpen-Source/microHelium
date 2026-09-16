<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\Rejudging;
use App\Services\RejudgingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issue #192 -- rejulgamento em lote com previa, aplicavel ou cancelavel
 * como conjunto.
 */
class RejudgingController extends Controller
{
    public function __construct(private RejudgingService $rejudgings) {}

    public function index(Request $request, Contest $contest): JsonResponse
    {
        $this->authorizeContestVisibility($contest);

        return response()->json([
            'data' => Rejudging::where('contest_id', $contest->id)
                ->with('creator:user_id,fullname,username')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    /**
     * Quantos runs um criterio pega, ANTES de criar conjunto nenhum.
     *
     * Existe separado do conjunto porque montar um conjunto dispara
     * julgamento de verdade: descobrir que o filtro pegou 4000 envios em vez
     * de 40 depois de a fila ja estar cheia e tarde. Aqui e so uma contagem.
     */
    public function dryRun(Request $request, Contest $contest): JsonResponse
    {
        $this->authorizeContestVisibility($contest);

        $validated = $this->validateFilters($request);
        $includeAccepted = (bool) ($validated['include_accepted'] ?? false);

        $query = $this->rejudgings->select($contest, $validated, $includeAccepted);

        return response()->json([
            'matches' => (clone $query)->count(),
            'accepted_excluded' => $includeAccepted
                ? 0
                : (clone $this->rejudgings->select($contest, $validated, true))->count()
                    - (clone $query)->count(),
            'include_accepted' => $includeAccepted,
        ]);
    }

    public function store(Request $request, Contest $contest): JsonResponse
    {
        $this->authorizeContestVisibility($contest);

        $validated = $this->validateFilters($request, [
            // Obrigatorio: um rejulgamento em lote muda a classificacao de
            // gente que nao pediu nada, e "por que" e a primeira pergunta de
            // quem contesta o resultado depois.
            'reason' => 'required|string|max:255',
        ]);

        $rejudging = $this->rejudgings->create(
            $contest,
            $this->onlyFilters($validated),
            $validated['reason'],
            (bool) ($validated['include_accepted'] ?? false),
            $request->user()
        );

        return response()->json($rejudging->fresh()->load('members'), 201);
    }

    public function show(Rejudging $rejudging): JsonResponse
    {
        $this->authorizeContestVisibility($rejudging->contest);

        return response()->json([
            'rejudging' => $rejudging,
            'preview' => $this->rejudgings->preview($rejudging),
        ]);
    }

    public function apply(Request $request, Rejudging $rejudging): JsonResponse
    {
        $this->authorizeContestVisibility($rejudging->contest);

        if (! $rejudging->isDecidable()) {
            return response()->json([
                'error' => "Este conjunto esta em \"{$rejudging->status}\" e nao pode ser aplicado. So um conjunto pronto, com todos os membros julgados, pode.",
            ], 422);
        }

        $this->rejudgings->apply($rejudging, $request->user());

        return response()->json([
            'rejudging' => $rejudging->fresh(),
            'preview' => $this->rejudgings->preview($rejudging),
        ]);
    }

    public function cancel(Request $request, Rejudging $rejudging): JsonResponse
    {
        $this->authorizeContestVisibility($rejudging->contest);

        if (! $rejudging->isOpen()) {
            return response()->json([
                'error' => "Este conjunto ja foi \"{$rejudging->status}\" e nao pode ser cancelado.",
            ], 422);
        }

        $this->rejudgings->cancel($rejudging, $request->user());

        return response()->json(['rejudging' => $rejudging->fresh()]);
    }

    /**
     * Os campos que os requisitos de CCS nomeiam, mais a sede.
     *
     * @param  array<string, string>  $extra
     * @return array<string, mixed>
     */
    private function validateFilters(Request $request, array $extra = []): array
    {
        return $request->validate(array_merge([
            'run_ids' => 'sometimes|array',
            'run_ids.*' => 'integer|exists:runs,id',
            'problem_id' => 'nullable|exists:problems,id',
            'language_id' => 'nullable|exists:languages,id',
            'user_id' => 'nullable|integer',
            'site_id' => 'nullable|exists:sites,id',
            'judgehost_id' => 'nullable|exists:judgehosts,id',
            'answer_id' => 'nullable|exists:answers,id',
            'contest_time_from' => 'nullable|integer|min:0',
            'contest_time_to' => 'nullable|integer|min:0',
            'include_accepted' => 'sometimes|boolean',
        ], $extra));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function onlyFilters(array $validated): array
    {
        return array_diff_key($validated, array_flip(['reason', 'include_accepted']));
    }
}
