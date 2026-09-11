<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\ProblemBank;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProblemBankController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return View
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
     * Issue #46 (docs/specs/46-bank-ownership.md) calls out this legacy
     * endpoint by name as needing the same ownership policy as the new
     * bank-governance screen. Today this route is also gated by the
     * `admin` middleware (routes/web.php), so the check below is currently
     * unreachable for non-admins -- it exists as defense-in-depth and so
     * this route stays correct if that route-level gate is ever loosened
     * (e.g. to let an org editor use it directly) without anyone having to
     * remember to add authorization here. Deleting is admin-only under the
     * current policy either way.
     *
     * @param  int  $id
     * @return RedirectResponse
     */
    public function destroy($id)
    {
        $problem = ProblemBank::findOrFail($id);
        $this->authorize('delete', $problem);

        $problem->delete();

        return redirect()->route('backend.problem-bank')->with('success', 'Problema removido do banco!');
    }

    /**
     * Toggle the active status of the specified resource.
     *
     * Same reasoning as destroy() above -- reapplies the issue #46 policy
     * as defense-in-depth, even though the `admin` route middleware
     * already blocks non-admins from reaching this action today.
     *
     * @param  int  $id
     * @return RedirectResponse
     */
    public function toggle($id)
    {
        $problem = ProblemBank::findOrFail($id);
        $this->authorize('update', $problem);

        $problem->is_active = ! $problem->is_active;
        $problem->version = $problem->version + 1;
        $problem->save();

        return redirect()->route('backend.problem-bank')->with('success', 'Status do problema atualizado!');
    }
}
