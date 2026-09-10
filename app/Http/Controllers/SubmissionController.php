<?php

namespace App\Http\Controllers;

use App\Models\Run;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class SubmissionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $userId = auth()->id();
        $submissions = collect([]);
        $acceptedCount = 0;
        $totalCount = 0;

        if ($userId && Schema::hasTable('runs')) {
            $submissions = DB::table('runs')
                ->leftJoin('problems', 'runs.problem_id', '=', 'problems.id')
                ->leftJoin('answers', 'runs.answer_id', '=', 'answers.id')
                ->leftJoin('languages', 'runs.language_id', '=', 'languages.id')
                ->where('runs.user_id', $userId)
                ->select('runs.*', 'problems.name as problem_name', 'problems.short_name as problem_letter',
                         'answers.short_name as result', 'languages.name as language')
                ->orderBy('runs.created_at', 'desc')
                ->get();
            $acceptedCount = $submissions->filter(fn($s) => in_array($s->result, ['Yes', 'AC', 'Accepted']))->count();
            $totalCount = $submissions->count();
        }

        return view('submissions', compact('submissions', 'acceptedCount', 'totalCount'));
    }

    /**
     * Show a single submission's detail (source code, judging output,
     * verdict). The list view's "Ver" action linked here but the route
     * never existed (404) -- see issue #30.
     */
    public function show(Run $run): View
    {
        $user = auth()->user();

        if (!$user->isAdmin() && !$user->isJudge() && $run->user_id !== $user->user_id) {
            abort(403, 'Voce nao pode ver esta submissao.');
        }

        $run->load(['problem', 'language', 'answer']);
        $sourceCode = file_exists($run->getSourcePath()) ? file_get_contents($run->getSourcePath()) : null;

        return view('submission-show', compact('run', 'sourceCode'));
    }
}