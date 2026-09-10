<?php

namespace App\Http\Controllers;

use App\Jobs\JudgeRunJob;
use App\Models\ContestLog;
use App\Models\Language;
use App\Models\Problem;
use App\Models\Run;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SubmitController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function create(Problem $problem): View
    {
        $this->authorizeProblemAccess($problem);

        $languages = Language::where('contest_id', $problem->contest_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('exercises.submit', [
            'exercise' => $problem,
            'problem' => $problem,
            'languages' => $languages,
        ]);
    }

    /**
     * Create a real Run for this problem and dispatch it to the auto-judge
     * queue -- replacing the old stub that just wrote a 'pending' row to the
     * legacy exercise_team table and never judged anything.
     */
    public function store(Request $request, Problem $problem): RedirectResponse
    {
        $this->authorizeProblemAccess($problem);

        $validated = $request->validate([
            'language_id' => 'required|exists:languages,id',
            'source_file' => 'nullable|file|max:' . config('autojudge.max_file_size', 100),
            'code_text' => 'nullable|string',
        ]);

        if (!$request->hasFile('source_file') && trim((string) $request->input('code_text')) === '') {
            return back()->withErrors(['source_file' => 'Envie um arquivo ou cole o codigo fonte.']);
        }

        $user = auth()->user();
        $contest = $problem->contest;
        $language = Language::findOrFail($validated['language_id']);

        if ($language->contest_id !== $problem->contest_id || !$language->is_active) {
            return back()->withErrors(['language_id' => 'Linguagem indisponivel para este problema.']);
        }

        if ($request->hasFile('source_file')) {
            $file = $request->file('source_file');
            $originalName = $this->sanitizeFilename($file->getClientOriginalName());
            $sourceContent = file_get_contents($file->path());
        } else {
            $originalName = 'main.' . $this->sanitizeFilename($language->extension);
            $sourceContent = $request->input('code_text');
        }

        $sourceHash = hash('sha256', $sourceContent);

        $duplicate = Run::where('contest_id', $contest->id)
            ->where('user_id', $user->user_id)
            ->where('problem_id', $problem->id)
            ->where('source_hash', $sourceHash)
            ->first();

        if ($duplicate) {
            return back()->withErrors(['source_file' => "Submissao identica ja enviada (run #{$duplicate->run_number})."]);
        }

        $path = "runs/{$contest->id}/{$user->user_id}/" . uniqid('run_', true) . '_' . $originalName;
        Storage::disk('local')->put($path, $sourceContent);

        $siteId = $user->site_id ?? $contest->sites()->value('id');

        $run = Run::create([
            'contest_id' => $contest->id,
            'site_id' => $siteId,
            'user_id' => $user->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
            'run_number' => Run::getNextRunNumber($contest->id, $siteId),
            'filename' => $originalName,
            'source_file' => $path,
            'source_hash' => $sourceHash,
            'contest_time' => $contest->getContestTime(),
            'status' => 'pending',
        ]);

        ContestLog::info($contest->id, "Run #{$run->run_number} submitted", [
            'user_id' => $user->user_id,
            'problem_id' => $problem->id,
            'language_id' => $language->id,
        ]);

        if ($problem->auto_judge) {
            JudgeRunJob::dispatch($run);
        }

        return redirect()->route('submissions')->with('success', 'Submissao enviada! Aguarde o julgamento.');
    }

    /**
     * A team must only be able to view/submit to problems that belong to
     * their own contest -- without this, a team could submit (and pollute
     * the scoreboard/logs of) any other contest's problem just by guessing
     * its numeric id, since Problem route-model-binding alone doesn't scope
     * by contest. Admins and judges are trusted across contests.
     */
    private function authorizeProblemAccess(Problem $problem): void
    {
        $user = auth()->user();

        if ($user->isAdmin() || $user->isJudge()) {
            return;
        }

        if ($user->contest_id !== $problem->contest_id) {
            abort(403, 'Este problema nao pertence ao seu contest.');
        }
    }

    /**
     * The client-supplied filename (getClientOriginalName()) is untrusted
     * input that was being concatenated straight into the storage path --
     * a name like "../../../../etc/cron.d/evil" would escape the intended
     * runs/{contest}/{user}/ directory. Strip any path component and only
     * keep a conservative character set.
     */
    private function sanitizeFilename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        $name = ltrim($name, '.');

        return $name !== '' ? substr($name, 0, 150) : 'source';
    }
}
