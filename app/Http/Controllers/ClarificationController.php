<?php

namespace App\Http\Controllers;

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
        // Get clarifications with user info
        $clarifications = DB::table('clarifications')
            ->leftJoin('users', 'clarifications.user_id', '=', 'users.user_id')
            ->select('clarifications.*', 'users.fullname as team_name')
            ->orderBy('clarifications.created_at', 'desc')
            ->get()
            ->map(function ($item) {
                $item->answered = $item->status === 'answered';
                $item->problem = $item->problem_id ? 'Problema #' . $item->problem_id : null;
                return $item;
            });
        $exercises = DB::table('exercises')->get();
        return view('clarifications', compact('clarifications', 'exercises'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $activeContest = DB::table('contests')->where('is_active', true)->first();

        if (!$activeContest) {
            return redirect()->route('clarifications')->with('error', 'Nenhuma competicao ativa no momento.');
        }

        // Assuming the first site associated with the contest is the one to use.
        $site = DB::table('sites')->where('contest_id', $activeContest->id)->first();
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
            'problem_id' => $request->input('problem_id') ?: null,
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