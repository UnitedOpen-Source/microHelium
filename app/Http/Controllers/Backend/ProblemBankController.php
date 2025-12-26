<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProblemBankController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $problems = DB::table('problem_bank')
            ->orderBy('difficulty')
            ->orderBy('name')
            ->get();
        return view('backend.problem-bank', compact('problems'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy($id)
    {
        DB::table('problem_bank')->where('id', $id)->delete();
        return redirect()->route('backend.problem-bank')->with('success', 'Problema removido do banco!');
    }

    /**
     * Toggle the active status of the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function toggle($id)
    {
        $problem = DB::table('problem_bank')->where('id', $id)->first();
        if ($problem) {
            DB::table('problem_bank')->where('id', $id)->update([
                'is_active' => !$problem->is_active,
                'updated_at' => now(),
            ]);
        }
        return redirect()->route('backend.problem-bank')->with('success', 'Status do problema atualizado!');
    }
}