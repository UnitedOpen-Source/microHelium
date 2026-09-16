<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Api\ContestController;
use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\ContestTimeAdjustment;
use App\Services\ContestAwards;
use App\Services\ContestClock;
use App\Services\ContestFinalizer;
use App\Services\ContestScoreRecomputer;
use Helium\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Session/CSRF presentation adapter for the existing contest services. */
class ContestOperationsController extends Controller
{
    public function show(Contest $contest, ContestFinalizer $finalizer, ContestAwards $awards, ContestClock $clock): View
    {
        abort_if($contest->is_practice, 404);
        $blockers = $finalizer->blockers($contest);
        // No prize preview before finalization: it could disclose a frozen result.
        $prizes = $contest->isFinalized() ? $awards->forContest($contest)['awards'] : [];
        $teams = User::where('contest_id', $contest->id)->pluck('fullname', 'user_id');

        // Issue #198/#233 -- os intervalos removidos da prova.
        //
        // Os DESFEITOS entram na lista: "reversivel" nao combina com sumir do
        // registro, e alguem vai perguntar depois por que aquela sede teve
        // quarenta minutos a mais.
        $adjustments = ContestTimeAdjustment::where('contest_id', $contest->id)
            ->withTrashed()
            ->with('site:id,name')
            ->orderBy('starts_at')
            ->get();

        $sites = $contest->sites()->orderBy('name')->pluck('name', 'id');

        // O fim efetivo POR SEDE, que e o que o operador precisa ver antes de
        // decidir: uma sede com tempo devolvido submete depois das outras, e
        // sem esta coluna a extensao e invisivel ate a prova acabar torto.
        $siteDeadlines = $sites->map(fn ($name, $id) => $clock->endTimeFor($contest, (int) $id));

        return view('backend.contest-operations', compact(
            'contest', 'blockers', 'prizes', 'teams', 'adjustments', 'sites', 'siteDeadlines'
        ));
    }

    /**
     * Issue #198/#233 -- registra um intervalo que a prova nao conta.
     *
     * Chama o mesmo servico que a API chama. Duas copias de uma regra que
     * mexe em classificacao e como uma delas fica para tras -- foi
     * exatamente o que o #225 encontrou nos atalhos legados.
     */
    public function storeTimeAdjustment(Request $request, Contest $contest, ContestScoreRecomputer $recomputer): RedirectResponse
    {
        abort_if($contest->is_practice, 404);

        $validated = $request->validate([
            'site_id' => 'nullable|exists:sites,id',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'reason' => 'required|string|max:255',
        ]);

        $adjustment = ContestTimeAdjustment::create([
            'contest_id' => $contest->id,
            'site_id' => $validated['site_id'] ?: null,
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'reason' => $validated['reason'],
            'created_by' => $request->user()?->user_id,
        ]);

        $recomputer->recompute($contest);

        ContestLog::warning($contest->id, "Intervalo removido da prova: {$adjustment->seconds()}s", [
            'event' => 'time_interval_removed',
            'adjustment_id' => $adjustment->id,
            'site_id' => $adjustment->site_id,
            'reason' => $adjustment->reason,
            'user_id' => $request->user()?->user_id,
        ]);

        $minutos = (int) round($adjustment->seconds() / 60);

        return back()->with('success', "Intervalo de {$minutos} min removido. O placar foi recalculado.");
    }

    /**
     * Desfaz. Nenhum `runs.contest_time` foi reescrito, entao desfazer nao
     * precisa lembrar de nada: o placar e derivado, e nao acumulado.
     */
    public function destroyTimeAdjustment(
        Request $request,
        Contest $contest,
        ContestTimeAdjustment $adjustment,
        ContestScoreRecomputer $recomputer,
    ): RedirectResponse {
        abort_if($contest->is_practice, 404);
        abort_if((int) $adjustment->contest_id !== (int) $contest->id, 404);

        $adjustment->delete();
        $recomputer->recompute($contest);

        ContestLog::warning($contest->id, 'Remocao de intervalo desfeita', [
            'event' => 'time_interval_restored',
            'adjustment_id' => $adjustment->id,
            'user_id' => $request->user()?->user_id,
        ]);

        return back()->with('success', 'Remoção desfeita. O placar voltou a contar aquele intervalo.');
    }

    public function finalize(Request $request, Contest $contest, ContestFinalizer $finalizer): RedirectResponse
    {
        abort_if($contest->is_practice, 404);

        return DB::transaction(function () use ($request, $contest, $finalizer) {
            $contest = Contest::whereKey($contest->id)->lockForUpdate()->firstOrFail();
            if ($contest->isFinalized()) {
                return back()->with('success', 'Esta competição já foi finalizada.');
            }
            $blockers = $finalizer->blockers($contest);
            if ($blockers !== []) {
                return back()->with('error', 'Ainda há impedimentos. Revise a lista atualizada antes de finalizar.');
            }
            $finalizer->finalize($contest, $request->user());

            return back()->with('success', 'Competição finalizada. A premiação está disponível abaixo.');
        });
    }

    public function unfreeze(Contest $contest, ContestController $controller): RedirectResponse
    {
        abort_if($contest->is_practice, 404);
        if ($contest->isRunning()) {
            return back()->with('error', 'A competição ainda está em andamento. Aguarde o término antes de revelar o placar.');
        }

        // Issue #198/#233 -- a recusa por sede ainda submetendo vem do
        // serviço da API, que é onde a regra mora. Aqui ela vira mensagem em
        // vez de 500: esta tela é operada durante a cerimônia.
        try {
            $controller->unfreeze($contest);
        } catch (HttpException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Placar revelado. Confira os impedimentos antes de finalizar.');
    }
}
