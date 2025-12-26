<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $teams = DB::table('teams')->orderBy('score', 'desc')->get();
        return view('backend.teams', compact('teams'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            'teamName' => 'required|string|max:255|unique:teams',
            'email' => 'nullable|email|max:255',
            'score' => 'nullable|integer',
        ]);

        DB::table('teams')->insert([
            'teamName' => $request->input('teamName'),
            'email' => $request->input('email'),
            'score' => $request->input('score', 0),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return redirect()->route('backend.teams')->with('success', 'Time criado com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy($id)
    {
        DB::table('teams')->where('team_id', $id)->delete();
        return redirect()->route('backend.teams')->with('success', 'Time excluido com sucesso!');
    }
}