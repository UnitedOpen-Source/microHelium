<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Helium\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

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
        // Grouped by contest in the view so an admin managing a multi-contest
        // install can't accidentally assign a user to a site belonging to a
        // different contest than intended.
        $sitesByContest = Site::with('contest:id,name')->orderBy('name')->get()->groupBy('contest.name');

        return view('backend.users', compact('users', 'sitesByContest'));
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
            // exists:sites,id queries the raw table and would accept a
            // soft-deleted site's id -- Site::find() below applies Eloquent's
            // SoftDeletingScope and returns null for that same id, which
            // would otherwise crash on ->contest_id.
            'site_id' => [
                'nullable',
                'required_if:user_type,' . User::TYPE_SITE,
                Rule::exists('sites', 'id')->whereNull('deleted_at'),
            ],
            // Issue #89: users.icpc_id has existed since
            // 2025_11_25_000007_update_users_table and had no way in. It is
            // what the ICPC standings report keys on, and a report with the
            // column blank is useless to whoever files it.
            'icpc_id' => ['nullable', 'string', 'max:50'],
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
            'icpc_id' => $validated['icpc_id'] ?? null,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return redirect()->route('backend.users')->with('success', 'Usuario criado com sucesso!');
    }

    /**
     * Issue #100 -- editing a user, which was not possible at all: this
     * controller had store() and destroy() and nothing in between, so the
     * only way to fix a typo was to delete the account. Deleting cascades
     * to its runs, scores, tasks and logs.
     */
    public function edit(User $user)
    {
        $sitesByContest = Site::with('contest:id,name')->orderBy('name')->get()->groupBy('contest.name');

        return view('backend.user-edit', compact('user', 'sitesByContest'));
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'fullname' => 'required|string|max:255',
            // Unique against everyone else, ignoring this row -- otherwise
            // saving a user without changing their username fails on their
            // own record.
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($user->user_id, 'user_id')],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->user_id, 'user_id')],
            // Optional on edit: an empty field means "leave the password
            // alone", not "set an empty password".
            'password' => 'nullable|string|min:8',
            'user_type' => 'required|in:' . implode(',', [
                User::TYPE_ADMIN, User::TYPE_JUDGE, User::TYPE_TEAM,
                User::TYPE_STAFF, User::TYPE_SCORE, User::TYPE_SITE,
            ]),
            'site_id' => [
                'nullable',
                'required_if:user_type,' . User::TYPE_SITE,
                Rule::exists('sites', 'id')->whereNull('deleted_at'),
            ],
            'icpc_id' => ['nullable', 'string', 'max:50'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $enabled = (bool) ($validated['is_enabled'] ?? false);
        $isSelf = (int) $user->user_id === (int) auth()->id();

        // Locking yourself out of your own install is not an edit anyone
        // means to make, and there is no second screen to undo it from.
        if ($isSelf && ! $enabled) {
            return back()->withInput()->withErrors(['is_enabled' => 'Voce nao pode desabilitar a sua propria conta.']);
        }

        if ($user->isAdmin() && $validated['user_type'] !== User::TYPE_ADMIN && $this->isLastAdmin($user)) {
            return back()->withInput()->withErrors(['user_type' => 'Esta e a ultima conta de administrador; rebaixa-la deixaria o sistema sem nenhum.']);
        }

        $siteId = $validated['site_id'] ?? null;

        $attributes = [
            'fullname' => $validated['fullname'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'user_type' => $validated['user_type'],
            'site_id' => $siteId,
            // The contest follows the site, exactly as it does on create.
            'contest_id' => $siteId ? Site::find($siteId)->contest_id : null,
            'icpc_id' => $validated['icpc_id'] ?? null,
            'is_enabled' => $enabled,
        ];

        if (! empty($validated['password'])) {
            $attributes['password'] = Hash::make($validated['password']);
        }

        $user->update($attributes);

        return redirect()->route('backend.users')->with('success', 'Usuario atualizado com sucesso!');
    }

    private function isLastAdmin(User $user): bool
    {
        return ! User::query()
            ->whereIn('user_type', [User::TYPE_ADMIN, User::TYPE_SYSTEM])
            ->where('user_id', '!=', $user->user_id)
            ->exists();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy($id)
    {
        $user = User::find($id);

        if (! $user) {
            return redirect()->route('backend.users')->with('success', 'Usuario excluido com sucesso!');
        }

        // Issue #100: the same two guards the edit path has. They matter
        // more here -- deleting cascades to the account's runs, scores,
        // tasks and logs, and there is no undo.
        if ((int) $user->user_id === (int) auth()->id()) {
            return back()->withErrors(['user' => 'Voce nao pode excluir a sua propria conta.']);
        }

        if ($user->isAdmin() && $this->isLastAdmin($user)) {
            return back()->withErrors(['user' => 'Esta e a ultima conta de administrador; excluí-la deixaria o sistema sem nenhum.']);
        }

        $user->delete();

        return redirect()->route('backend.users')->with('success', 'Usuario excluido com sucesso!');
    }
}