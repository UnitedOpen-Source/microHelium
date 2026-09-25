<?php

namespace App\Http\Controllers;

use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\Task;
use App\Services\RunSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Issue #94 -- a team sends a file to be printed, and it lands in the staff
 * queue at their site (BOCA's src/team/task.php, the CD-MOJ's lib/print.sh).
 *
 * This is the other half of #87. Tasks.filename and tasks.file_path existed
 * from the original migration and had no producer -- the balloon feature
 * never fills them -- so the columns were dead until now.
 */
class PrintRequestController extends Controller
{
    public function __construct(private RunSubmissionService $submissions)
    {
        $this->middleware(['auth', 'role:team']);
    }

    public function create(): View
    {
        return view('print', [
            'contest' => $this->contest(),
            'maxKb' => (int) config('printing.max_file_size_kb', 512),
            'extensions' => config('printing.allowed_extensions', []),
            'requests' => Task::query()
                ->where('user_id', auth()->id())
                ->where('is_system', false)
                ->where('status', '!=', 'deleted')
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $contest = $this->contest();

        // Same rule the submit path applies, for the same reason: nothing
        // queues before the contest starts or after it ends.
        if (! $contest || ! $contest->isRunning()) {
            return back()->withErrors(['file' => __('A competição não está em andamento no momento.')]);
        }

        $maxKb = (int) config('printing.max_file_size_kb', 512);
        $extensions = config('printing.allowed_extensions', []);

        $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKb, 'mimes:'.implode(',', $extensions)],
            'description' => ['nullable', 'string', 'max:120'],
        ], [
            'file.max' => __('O arquivo ultrapassa o limite de :max KB.', ['max' => $maxKb]),
            'file.mimes' => __('Envie um arquivo de texto, código-fonte ou PDF.'),
        ]);

        $user = $request->user();
        $siteId = $user->site_id ?? $contest->sites()->value('id');

        if (! $siteId) {
            return back()->withErrors(['file' => __('Sua conta não está associada a nenhuma sede.')]);
        }

        $uploaded = $request->file('file');
        // The client-supplied name ends up in a storage path; reuse the
        // sanitiser the submit path already needed for the same reason.
        $filename = $this->submissions->sanitize($uploaded->getClientOriginalName());

        $task = DB::transaction(function () use ($contest, $siteId, $user, $uploaded, $filename, $request) {
            $path = $uploaded->storeAs(
                "prints/{$contest->id}/{$user->user_id}",
                uniqid('print_', true).'_'.$filename,
                'local'
            );

            // Same lock as run numbering and balloons: two concurrent
            // requests must not compute the same MAX(task_number)+1.
            Task::where('contest_id', $contest->id)->where('site_id', $siteId)->lockForUpdate()->get();

            return Task::create([
                'contest_id' => $contest->id,
                'site_id' => $siteId,
                'user_id' => $user->user_id,
                'task_number' => Task::getNextTaskNumber($contest->id, $siteId),
                'description' => $this->description($request->input('description'), $filename),
                'filename' => $filename,
                'file_path' => $path,
                'contest_time' => $contest->getContestTime(),
                'status' => 'pending',
                // Not is_system: this one a person asked for.
                'is_system' => false,
            ]);
        });

        ContestLog::info($contest->id, "Print request #{$task->task_number} received", [
            'user_id' => $user->user_id,
            'filename' => $filename,
        ]);

        return redirect()->route('print.create')
            ->with('success', __('Pedido de impressão #:number enviado para a equipe de apoio.', ['number' => $task->task_number]));
    }

    private function description(?string $note, string $filename): string
    {
        $note = trim((string) $note);
        $description = 'Impressao: '.$filename;

        if ($note !== '') {
            $description .= ' - '.$note;
        }

        // The column is varchar(200).
        return mb_substr($description, 0, 200);
    }

    private function contest(): ?Contest
    {
        $user = auth()->user();

        return $user->contest_id ? Contest::query()->competition()->find($user->contest_id) : null;
    }
}
