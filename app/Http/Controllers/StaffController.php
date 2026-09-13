<?php

namespace App\Http\Controllers;

use App\Models\Contest;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Staff task screen (BOCA's staff/task.php) -- printing/balloon-delivery
 * style tasks that a staff member claims and marks done.
 */
class StaffController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'role:staff,admin']);
    }

    public function tasks(): View
    {
        $contest = $this->resolveContest();

        $user = auth()->user();

        $tasks = $contest
            ? Task::where('contest_id', $contest->id)
                ->where('status', '!=', 'deleted')
                // Issue #87: a balloon is walked to a desk in one room. A
                // staff member assigned to a site sees that site's queue,
                // not every site's -- BOCA and the CD-MOJ both scope this
                // the same way. An admin, and a staff account with no site,
                // still see everything.
                ->when($user->site_id && ! $user->isAdmin(), fn ($query) => $query->where('site_id', $user->site_id))
                ->with(['user:user_id,fullname,username', 'staff:user_id,fullname,username'])
                ->orderBy('status')
                ->orderByDesc('created_at')
                ->get()
            : collect();

        return view('staff.tasks', compact('contest', 'tasks'));
    }

    public function complete(Task $task): RedirectResponse
    {
        $this->authorizeTaskAccess($task);

        $task->update([
            'status' => 'done',
            // Contest uses SoftDeletes -- a Task can outlive its contest
            // being soft-deleted, so ->contest can resolve to null here.
            'completed_time' => $task->contest?->getContestTime() ?? 0,
            'staff_id' => auth()->id(),
            'staff_site_id' => auth()->user()->site_id,
        ]);

        return redirect()->route('staff.tasks')->with('success', "Tarefa #{$task->task_number} marcada como concluida.");
    }

    private function resolveContest(): ?Contest
    {
        $user = auth()->user();

        if ($user->contest_id) {
            return Contest::find($user->contest_id);
        }

        // Issue #43: practice has no tasks/balloons.
        return Contest::query()->competition()->where('is_active', true)->first();
    }

    private function authorizeTaskAccess(Task $task): void
    {
        $user = auth()->user();

        $this->authorizeScopedAccess(
            $user->contest_id,
            $task->contest_id,
            'Voce nao pode concluir tarefas de outro contest.'
        );

        // Issue #87: the listing scopes by site, so the action has to as
        // well -- otherwise another site's task is still completable by
        // guessing its id.
        if ($user->site_id && ! $user->isAdmin() && $task->site_id !== $user->site_id) {
            abort(403, 'Voce nao pode concluir tarefas de outra sede.');
        }
    }
}
