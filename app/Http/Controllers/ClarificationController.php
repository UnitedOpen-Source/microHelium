<?php

namespace App\Http\Controllers;

use App\Models\Contest;
use App\Models\Problem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClarificationController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        // Issue #106: this dropdown used to be filled from `exercises`, the
        // 2017 Helium table, which a wizard-created contest never writes to.
        // The list was therefore always empty and a team could only ever ask
        // a "Geral" clarification, never one about a problem. Worse,
        // clarifications.problem_id is a foreign key to `problems`, so an
        // exercise_id written there would have pointed at the wrong problem
        // or violated the constraint.
        $contest = $this->resolveContest();

        $problems = $contest
            ? Problem::where('contest_id', $contest->id)
                ->orderBy('sort_order')
                ->get(['id', 'short_name', 'name'])
            : collect();

        $labels = $problems->mapWithKeys(fn ($problem) => [
            $problem->id => trim($problem->short_name.' - '.$problem->name),
        ]);

        $clarifications = DB::table('clarifications')
            ->leftJoin('users', 'clarifications.user_id', '=', 'users.user_id')
            ->when($contest, fn ($query) => $query->where('clarifications.contest_id', $contest->id))
            ->select('clarifications.*', 'users.fullname as team_name')
            ->orderBy('clarifications.created_at', 'desc')
            ->get()
            ->map(function ($item) use ($labels) {
                $item->answered = $item->status === 'answered';
                // The letter and name the team actually chose, rather than
                // "Problema #7".
                $item->problem = $item->problem_id
                    ? ($labels[$item->problem_id] ?? 'Problema #'.$item->problem_id)
                    : null;

                return $item;
            });

        return view('clarifications', compact('clarifications', 'problems'));
    }

    private function resolveProblemId(mixed $raw, int $contestId): ?int
    {
        if (! is_scalar($raw) || $raw === '') {
            return null;
        }

        return Problem::where('contest_id', $contestId)->whereKey((int) $raw)->value('id');
    }

    /**
     * The contest this screen is about: the viewer's own when they have one,
     * otherwise the running competition. Never the practice contest (#43).
     */
    private function resolveContest(): ?Contest
    {
        $user = auth()->user();

        if ($user && $user->contest_id) {
            return Contest::query()->competition()->find($user->contest_id);
        }

        return Contest::query()->competition()->where('is_active', true)->first();
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        // Issue #43: the practice contest is not an event and has no
        // clarifications.
        $activeContest = DB::table('contests')->where('is_active', true)->where('is_practice', false)->first();

        if (!$activeContest) {
            return redirect()->route('clarifications')->with('error', 'Nenhuma competicao ativa no momento.');
        }

        // Use the asking user's own site when they have one -- falling back
        // to the contest's first site only for accounts with no site_id.
        // Always defaulting to "the first site" mistagged every question
        // from a team at any other site, which would have made site-scoped
        // clarification answering (issue #18) silently show the wrong
        // (or no) questions to that site's coordinator/judges.
        // Constrained to $activeContest's id too: nothing deactivates other
        // contests when one is activated, so more than one row can have
        // is_active=true. If the user's own site happens to belong to a
        // different contest than the one resolved here, using it anyway
        // would insert a clarification whose contest_id and site_id
        // disagree on which contest they belong to.
        $siteId = auth()->user()->site_id;
        $site = $siteId
            ? DB::table('sites')->where('id', $siteId)->where('contest_id', $activeContest->id)->first()
            : null;
        $site ??= DB::table('sites')->where('contest_id', $activeContest->id)->first();
        if (!$site) {
            return redirect()->route('clarifications')->with('error', 'O concurso ativo nao possui um site configurado.');
        }

        $maxNumber = DB::table('clarifications')
            ->where('contest_id', $activeContest->id)
            ->where('site_id', $site->id)
            ->max('clarification_number') ?? 0;

        DB::table('clarifications')->insert([
            'contest_id' => $activeContest->id,
            'site_id' => $site->id,
            'user_id' => auth()->id(),
            // Only a problem of this very contest. Without this an id from
            // anywhere could be posted straight into a column whose foreign
            // key points at `problems`.
            'problem_id' => $this->resolveProblemId($request->input('problem_id'), $activeContest->id),
            'clarification_number' => $maxNumber + 1,
            'question' => $request->input('question'),
            'contest_time' => 0, // This should probably be calculated based on contest start time
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return redirect()->route('clarifications')->with('success', 'Pergunta enviada com sucesso!');
    }
}