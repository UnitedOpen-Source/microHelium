<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\ContestTimeAdjustment;
use App\Services\ContestScoreRecomputer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issue #198 -- registrar e desfazer um pedaco de tempo que a prova nao
 * conta.
 */
class ContestTimeAdjustmentController extends Controller
{
    public function __construct(private ContestScoreRecomputer $recomputer) {}

    public function index(Contest $contest): JsonResponse
    {
        $this->authorizeContestVisibility($contest);

        return response()->json([
            'data' => ContestTimeAdjustment::where('contest_id', $contest->id)
                ->withTrashed()
                ->with(['site:id,name', 'creator:user_id,fullname,username'])
                ->orderBy('starts_at')
                ->get()
                ->map(fn (ContestTimeAdjustment $adjustment) => [
                    'id' => $adjustment->id,
                    'site_id' => $adjustment->site_id,
                    'site_name' => $adjustment->site?->name,
                    'starts_at' => $adjustment->starts_at->toISOString(),
                    'ends_at' => $adjustment->ends_at->toISOString(),
                    'seconds' => $adjustment->seconds(),
                    'reason' => $adjustment->reason,
                    // Desfeitos continuam listados: "reversivel" nao combina
                    // com sumir do registro, e alguem vai perguntar depois
                    // por que aquela sede teve quarenta minutos a mais.
                    'reverted' => $adjustment->trashed(),
                ])->values(),
        ]);
    }

    public function store(Request $request, Contest $contest): JsonResponse
    {
        $this->authorizeContestVisibility($contest);

        $validated = $request->validate([
            'site_id' => 'nullable|exists:sites,id',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'reason' => 'required|string|max:255',
        ]);

        $adjustment = ContestTimeAdjustment::create([
            'contest_id' => $contest->id,
            'site_id' => $validated['site_id'] ?? null,
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'reason' => $validated['reason'],
            'created_by' => $request->user()?->user_id,
        ]);

        $cells = $this->recomputer->recompute($contest);

        ContestLog::warning($contest->id, "Intervalo removido da prova: {$adjustment->seconds()}s", [
            'event' => 'time_interval_removed',
            'adjustment_id' => $adjustment->id,
            'site_id' => $adjustment->site_id,
            'reason' => $adjustment->reason,
            'user_id' => $request->user()?->user_id,
            'recomputed_cells' => $cells,
        ]);

        return response()->json(['data' => ['id' => $adjustment->id, 'seconds' => $adjustment->seconds()]], 201);
    }

    /**
     * "The removal of a time interval must be reversible."
     *
     * Um soft delete e um recalculo. Como nenhum `runs.contest_time` foi
     * reescrito, desfazer nao precisa lembrar de nada -- o placar volta a
     * ser o que era porque e derivado, e nao acumulado.
     */
    public function destroy(Request $request, Contest $contest, ContestTimeAdjustment $adjustment): JsonResponse
    {
        $this->authorizeContestVisibility($contest);

        if ((int) $adjustment->contest_id !== (int) $contest->id) {
            abort(404);
        }

        $adjustment->delete();

        $cells = $this->recomputer->recompute($contest);

        ContestLog::warning($contest->id, 'Remocao de intervalo desfeita', [
            'event' => 'time_interval_restored',
            'adjustment_id' => $adjustment->id,
            'user_id' => $request->user()?->user_id,
            'recomputed_cells' => $cells,
        ]);

        return response()->json(['data' => ['id' => $adjustment->id, 'reverted' => true]]);
    }
}
