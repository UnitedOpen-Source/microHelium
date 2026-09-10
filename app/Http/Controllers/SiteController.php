<?php

namespace App\Http\Controllers;

use App\Models\Clarification;
use App\Models\Contest;
use App\Models\Run;
use App\Models\Task;
use Helium\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Site coordinator screens (issue #18): a read-only view of one physical
 * site's submissions/scoreboard, plus site-scoped versions of staff tasks,
 * team management, and clarification answering. Everything here is scoped
 * to auth()->user()->site_id -- an admin can access these routes too (for
 * spot-checking a site) but sees the same site-scoped data a coordinator
 * would, not a global view (unlike Judge/Staff/Backend controllers, which
 * trust admins with the full contest).
 */
class SiteController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'role:site,admin']);
    }

    public function dashboard(): View
    {
        $site = $this->resolveSite();
        $contest = $site?->contest;

        $recentRuns = collect();
        $scoreboard = collect();

        if ($site) {
            $recentRuns = Run::where('site_id', $site->id)
                ->with(['problem:id,short_name,name', 'user:user_id,fullname,username', 'answer:id,name,short_name,is_accepted'])
                ->orderByDesc('created_at')
                ->take(20)
                ->get();

            $scoreboard = User::where('site_id', $site->id)
                ->where('user_type', User::TYPE_TEAM)
                ->get(['user_id', 'fullname', 'username']);
        }

        return view('site.dashboard', compact('site', 'contest', 'recentRuns', 'scoreboard'));
    }

    public function tasks(): View
    {
        $site = $this->resolveSite();

        $tasks = $site
            ? Task::where('site_id', $site->id)
                ->where('status', '!=', 'deleted')
                ->with(['user:user_id,fullname,username', 'staff:user_id,fullname,username'])
                ->orderByDesc('created_at')
                ->get()
            : collect();

        return view('site.tasks', compact('site', 'tasks'));
    }

    public function completeTask(Task $task): RedirectResponse
    {
        $this->authorizeSiteAccess($task->site_id, 'tarefas');

        $task->update([
            'status' => 'done',
            // Contest uses SoftDeletes -- a Task can outlive its contest
            // being soft-deleted, so ->contest can resolve to null here.
            'completed_time' => $task->contest?->getContestTime() ?? 0,
            'staff_id' => auth()->id(),
            'staff_site_id' => $task->site_id,
        ]);

        return redirect()->route('site.tasks')->with('success', "Tarefa #{$task->task_number} marcada como concluida.");
    }

    public function teams(): View
    {
        $site = $this->resolveSite();

        $teams = $site
            ? User::where('site_id', $site->id)->where('user_type', User::TYPE_TEAM)->orderBy('fullname')->get()
            : collect();

        return view('site.teams', compact('site', 'teams'));
    }

    public function storeTeam(Request $request): RedirectResponse
    {
        $site = $this->resolveSite();
        abort_if(!$site, 422, 'Sua conta nao esta vinculada a um site.');

        $validated = $request->validate([
            'fullname' => 'required|string|max:255',
            'username' => 'required|string|max:255|unique:users',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        DB::table('users')->insert([
            'fullname' => $validated['fullname'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'user_type' => User::TYPE_TEAM,
            'site_id' => $site->id,
            'contest_id' => $site->contest_id,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('site.teams')->with('success', 'Time criado com sucesso!');
    }

    public function clarifications(): View
    {
        $site = $this->resolveSite();

        $clarifications = $site
            ? DB::table('clarifications')
                ->leftJoin('users', 'clarifications.user_id', '=', 'users.user_id')
                ->where('clarifications.site_id', $site->id)
                ->select('clarifications.*', 'users.fullname as team_name')
                ->orderBy('clarifications.status', 'asc')
                ->orderBy('clarifications.created_at', 'desc')
                ->get()
                ->map(function ($item) {
                    // Matches Clarification::isAnswered() -- a raw
                    // status === 'answered' check misses broadcast_site/
                    // broadcast_all, which would show an already-answered
                    // (and broadcast) question as pending here, and let a
                    // resubmit silently overwrite the broadcast answer.
                    $item->answered = in_array($item->status, ['answered', 'broadcast_site', 'broadcast_all']);
                    $item->problem = $item->problem_id ? 'Problema #' . $item->problem_id : null;
                    return $item;
                })
            : collect();

        return view('site.clarifications', compact('site', 'clarifications'));
    }

    public function answerClarification(Request $request, Clarification $clarification): RedirectResponse
    {
        $this->authorizeSiteAccess($clarification->site_id, 'clarificacoes');

        $request->validate(['answer' => 'required|string']);

        $clarification->update([
            'answer' => $request->input('answer'),
            'status' => 'answered',
            'answered_time' => $clarification->contest?->getContestTime() ?? 0,
        ]);

        return redirect()->route('site.clarifications')->with('success', 'Resposta enviada!');
    }

    /**
     * A site coordinator is always locked to their own site_id, regardless
     * of any ?site_id= query param -- otherwise they could spot-check (or
     * manage) another site's data just by editing the URL. Admins normally
     * have no site_id of their own, so they may pick a site to view via
     * ?site_id= instead (read-only spot-checking, per this controller's
     * class doc); with neither, there's nothing to show.
     */
    private function resolveSite(): ?\App\Models\Site
    {
        $user = auth()->user();

        if (!$user->isAdmin()) {
            return $user->site;
        }

        return $user->site ?? \App\Models\Site::find(request()->query('site_id'));
    }

    /**
     * Mirrors JudgeController::authorizeRunAccess()/StaffController::
     * authorizeTaskAccess() -- role:site,admin only checks the user's type,
     * not which site the target record belongs to. Without this a
     * coordinator for site A could complete a task or answer a
     * clarification belonging to site B just by guessing/incrementing the
     * id. Admins are trusted across sites, matching the rest of the admin
     * surface.
     */
    private function authorizeSiteAccess(?int $targetSiteId, string $resource): void
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return;
        }

        if ($user->site_id !== $targetSiteId) {
            abort(403, "Voce nao pode gerenciar {$resource} de outro site.");
        }
    }
}
