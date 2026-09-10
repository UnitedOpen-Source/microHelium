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

        $tasks = $contest
            ? Task::where('contest_id', $contest->id)
                ->where('status', '!=', 'deleted')
                ->with(['user:user_id,fullname,username', 'staff:user_id,fullname,username'])
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
            'completed_time' => $task->contest->getContestTime(),
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

        return Contest::where('is_active', true)->first();
    }

    /**
     * A staff member must only be able to complete tasks in their own
     * contest -- the `role:staff,admin` middleware only checks the user's
     * type, not which contest the task belongs to, so without this a staff
     * member from contest A could mark a task in contest B done just by
     * guessing/incrementing the task id. Admins are trusted across
     * contests, matching the rest of the admin surface.
     */
    private function authorizeTaskAccess(Task $task): void
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return;
        }

        if ($user->contest_id !== $task->contest_id) {
            abort(403, 'Voce nao pode concluir tarefas de outro contest.');
        }
    }
}
