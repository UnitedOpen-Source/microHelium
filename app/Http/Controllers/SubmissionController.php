<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
}