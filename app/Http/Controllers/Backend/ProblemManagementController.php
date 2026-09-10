<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\Problem;
use App\Models\ProblemBank;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Real Problem management for a contest -- replaces "Gerenciar Problemas"
 * pointing at the legacy exercises table (see issue #34). The contest
 * wizard already creates Problem rows from the Problem Bank at contest
 * creation time; this adds the missing piece: adding more problems to a
 * contest that already exists, reusing the exact same logic
 * (ContestWizardController::addProblemsFromBank()).
 */
class ProblemManagementController extends Controller
{
    public function index(Request $request): View
    {
        $contests = Contest::orderByDesc('created_at')->get();

        $contestId = (int) $request->query('contest_id', 0);
        // A requested id that doesn't match any contest (stale bookmark,
        // deleted contest) must not be confused with "no contests exist at
        // all" -- fall back to the first available contest either way.
        $contest = ($contestId ? $contests->firstWhere('id', $contestId) : null)
            ?? $contests->first();

        $problems = collect();
        $availableBankItems = collect();

        if ($contest) {
            $problems = Problem::where('contest_id', $contest->id)
                ->withCount('testCases')
                ->orderBy('sort_order')
                ->get();

            $addedBasenames = $problems->pluck('basename');

            $availableBankItems = ProblemBank::active()
                ->orderBy('name')
                ->get()
                ->reject(fn (ProblemBank $item) => $addedBasenames->contains(Str::slug($item->code)));
        }

        return view('backend.problems', [
            'contests' => $contests,
            'contest' => $contest,
            'problems' => $problems,
            'availableBankItems' => $availableBankItems,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'contest_id' => 'required|exists:contests,id',
            'problems' => 'required|array|min:1',
            'problems.*' => 'integer|exists:problem_bank,id',
        ]);

        $added = ContestWizardController::addProblemsFromBank(
            (int) $validated['contest_id'],
            $validated['problems']
        );

        $message = $added > 0
            ? "{$added} problema(s) adicionado(s) ao contest."
            : 'Nenhum problema novo adicionado (ja estavam todos neste contest).';

        return redirect()->route('backend.exercises', ['contest_id' => $validated['contest_id']])->with('success', $message);
    }
}
