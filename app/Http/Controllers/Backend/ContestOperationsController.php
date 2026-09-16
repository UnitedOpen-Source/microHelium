<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Api\ContestController;
use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Services\ContestAwards;
use App\Services\ContestFinalizer;
use Helium\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Session/CSRF presentation adapter for the existing contest services. */
class ContestOperationsController extends Controller
{
    public function show(Contest $contest, ContestFinalizer $finalizer, ContestAwards $awards): View
    {
        abort_if($contest->is_practice, 404);
        $blockers = $finalizer->blockers($contest);
        // No prize preview before finalization: it could disclose a frozen result.
        $prizes = $contest->isFinalized() ? $awards->forContest($contest)['awards'] : [];
        $teams = User::where('contest_id', $contest->id)->pluck('fullname', 'user_id');

        return view('backend.contest-operations', compact('contest', 'blockers', 'prizes', 'teams'));
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
        $controller->unfreeze($contest);

        return back()->with('success', 'Placar revelado. Confira os impedimentos antes de finalizar.');
    }
}
