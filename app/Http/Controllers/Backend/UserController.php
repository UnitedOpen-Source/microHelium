<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Helium\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $users = DB::table('users')->orderBy('user_id', 'desc')->get();
        $sites = Site::orderBy('name')->get();

        return view('backend.users', compact('users', 'sites'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        // site_id was previously never set on insert despite the column and
        // Site relation existing on the User model -- there was no way to
        // assign any created user (judge, staff, or now site coordinator)
        // to a site through this form at all.
        $validated = $request->validate([
            'fullname' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'user_type' => 'required|in:' . implode(',', [
                User::TYPE_ADMIN, User::TYPE_JUDGE, User::TYPE_TEAM,
                User::TYPE_STAFF, User::TYPE_SCORE, User::TYPE_SITE,
            ]),
            'site_id' => 'nullable|required_if:user_type,' . User::TYPE_SITE . '|exists:sites,id',
        ]);

        $siteId = $validated['site_id'] ?? null;

        DB::table('users')->insert([
            'fullname' => $validated['fullname'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'user_type' => $validated['user_type'],
            'site_id' => $siteId,
            'contest_id' => $siteId ? Site::find($siteId)->contest_id : null,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return redirect()->route('backend.users')->with('success', 'Usuario criado com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy($id)
    {
        DB::table('users')->where('user_id', $id)->delete();
        return redirect()->route('backend.users')->with('success', 'Usuario excluido com sucesso!');
    }
}