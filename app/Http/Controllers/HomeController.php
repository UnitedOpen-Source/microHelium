<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HomeController extends Controller
{
    /**
     * Show the application dashboard with statistics.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $totalProblems = DB::table('exercises')->count();
        $totalTeams = DB::table('teams')->count();

        // Use runs table (BOCA schema) for submissions
        $totalSubmissions = 0;
        $acceptedSubmissions = 0;
        $recentSubmissions = collect([]);

        if (Schema::hasTable('runs')) {
            $totalSubmissions = DB::table('runs')->count();
            // Accepted submissions have answer_id = 1 (typically "Yes/Accepted")
            $acceptedSubmissions = DB::table('runs')
                ->leftJoin('answers', 'runs.answer_id', '=', 'answers.id')
                ->where('answers.is_accepted', true)
                ->count();
            $recentSubmissions = DB::table('runs')
                ->leftJoin('users', 'runs.user_id', '=', 'users.user_id')
                ->leftJoin('problems', 'runs.problem_id', '=', 'problems.id')
                ->leftJoin('answers', 'runs.answer_id', '=', 'answers.id')
                ->leftJoin('languages', 'runs.language_id', '=', 'languages.id')
                ->select('runs.*', 'users.fullname as team_name', 'problems.name as problem_name',
                         'answers.short_name as result', 'languages.name as language')
                ->orderBy('runs.created_at', 'desc')
                ->limit(10)
                ->get();
        }

        return view('home', compact('totalProblems', 'totalTeams', 'totalSubmissions', 'acceptedSubmissions', 'recentSubmissions'));
    }
}