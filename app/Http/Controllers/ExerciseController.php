<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExerciseController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $exercises = DB::table('exercises')->get();
        return view('exercises.index', compact('exercises'));
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\View\View
     */
    public function show($id)
    {
        $exercise = DB::table('exercises')->where('exercise_id', $id)->first();
        if (!$exercise) {
            abort(404);
        }
        return view('exercises.show', compact('exercise'));
    }
}