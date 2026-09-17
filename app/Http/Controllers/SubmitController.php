<?php

namespace App\Http\Controllers;

use App\Models\Contest;
use App\Models\Language;
use App\Models\Problem;
use App\Services\ContestClock;
use App\Services\DuplicateSubmissionException;
use App\Services\RunSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubmitController extends Controller
{
    public function __construct(private RunSubmissionService $submissions)
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

        // Matches the same check Api\RunController::store() already does --
        // without it, this web path could accept submissions before the
        // contest starts, after it ends, or while manually deactivated.
        // Issue #198 -- e a mesma pergunta por sede que Api\RunController
        // faz. As duas portas tem que concordar: uma sede com tempo
        // devolvido que pudesse submeter pela API e nao pela web teria o
        // tempo de volta so para quem soubesse usar a API.
        if (! app(ContestClock::class)->isRunningFor($problem->contest, auth()->user()?->site_id)) {
            return back()->withErrors(['source_file' => 'Este contest nao esta em andamento no momento.']);
        }

        // Issue #284 -- o limite da prova, e nao a constante da instalacao.
        // `contests.max_file_size` e coletado pelo assistente, pela edicao,
        // pela API e pelo importador, e ate aqui nao era lido por ninguem: a
        // tela mostrava 1024 KB e o envio recusava acima de 100.
        //
        // O `code_text` abaixo deriva deste mesmo numero, entao o caminho de
        // colar codigo acompanha -- de proposito: as duas portas para o
        // mesmo envio nao podem discordar sobre o tamanho aceito.
        $maxFileSizeKb = $problem->contest?->maxSourceKb() ?? Contest::defaultMaxSourceKb();

        $validated = $request->validate([
            'language_id' => 'required|exists:languages,id',
            'source_file' => 'nullable|file|max:'.$maxFileSizeKb,
            // Same size cap as the file upload path (in KB), applied to
            // characters -- previously unbounded, letting the pasted-code
            // path bypass the upload size limit entirely.
            'code_text' => 'nullable|string|max:'.($maxFileSizeKb * 1024),
        ]);

        if (! $request->hasFile('source_file') && trim((string) $request->input('code_text')) === '') {
            return back()->withErrors(['source_file' => 'Envie um arquivo ou cole o codigo fonte.']);
        }

        $user = auth()->user();
        $contest = $problem->contest;
        $language = Language::findOrFail($validated['language_id']);

        if ($language->contest_id !== $problem->contest_id || ! $language->is_active) {
            return back()->withErrors(['language_id' => 'Linguagem indisponivel para este problema.']);
        }

        if ($request->hasFile('source_file')) {
            $file = $request->file('source_file');
            // The compile/run commands are chosen by the selected language,
            // not by sniffing the file -- so the extension must always match
            // what compiles for this language, regardless of what the user
            // named their file (e.g. picking "C" but uploading "solution.txt"
            // would otherwise make gcc fail on a filename it doesn't
            // recognize). Language::getFileExtension() resolves the *real*
            // file extension (e.g. "c") -- $language->extension alone is
            // sometimes a compiler-variant id (e.g. "c_gcc13", seeded by the
            // contest wizard from Language::getDefaultLanguages()), not a
            // usable file extension, and passing that straight to gcc/javac
            // makes every wizard-created contest's submissions fail to
            // compile. The basename is preserved since some languages (Java)
            // require it to match the program's class/entry-point name.
            $basename = pathinfo($this->sanitizeFilename($file->getClientOriginalName()), PATHINFO_FILENAME);
            $originalName = ($basename !== '' ? $basename : 'main').'.'.$this->sanitizeFilename($language->getFileExtension());
            $sourceContent = file_get_contents($file->path());
        } else {
            $originalName = 'main.'.$this->sanitizeFilename($language->getFileExtension());
            $sourceContent = $request->input('code_text');
        }

        $siteId = $user->site_id ?? $contest->sites()->value('id');

        if (! $siteId) {
            return back()->withErrors(['source_file' => 'Este contest ainda nao tem nenhum site configurado; nao e possivel submeter.']);
        }

        try {
            // Run numbering, storage layout, logging and dispatch live in the
            // shared service (issue #43 asked for it: "reusar avaliação,
            // validação de linguagem/fonte e isolamento do juiz por serviço
            // compartilhado com contexto explícito"). What stays here is what
            // is specific to a competition submission -- the contest clock
            // check above, and refusing an identical resubmission below.
            $this->submissions->submit(
                contest: $contest,
                siteId: $siteId,
                user: $user,
                problem: $problem,
                language: $language,
                filename: $originalName,
                source: $sourceContent,
                rejectDuplicateSource: true,
            );
        } catch (DuplicateSubmissionException $e) {
            return back()->withErrors(['source_file' => $e->getMessage()]);
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
