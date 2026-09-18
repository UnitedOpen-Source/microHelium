<?php

namespace App\Http\Controllers;

use App\Models\Run;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class SubmissionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return View
     */
    public function index()
    {
        $userId = auth()->id();
        $submissions = collect([]);
        $acceptedCount = 0;
        $totalCount = 0;

        if ($userId && Schema::hasTable('runs')) {
            $submissions = DB::table('runs')
                ->leftJoin('problems', 'runs.problem_id', '=', 'problems.id')
                ->leftJoin('answers', 'runs.answer_id', '=', 'answers.id')
                ->leftJoin('languages', 'runs.language_id', '=', 'languages.id')
                // Issue #138: this screen is a query builder join, not
                // Eloquent, so nothing on the Run model can protect it --
                // the contest's gate flag has to be fetched here or the
                // masking below has nothing to decide on.
                ->leftJoin('contests', 'runs.contest_id', '=', 'contests.id')
                ->where('runs.user_id', $userId)
                ->select('runs.*', 'problems.name as problem_name', 'problems.short_name as problem_letter',
                    'answers.short_name as result', 'languages.name as language',
                    'contests.verification_required as contest_verification_required')
                ->orderBy('runs.created_at', 'desc')
                ->get()
                ->map(fn ($submission) => $this->maskWithheldRow($submission));

            // Counted AFTER the mask, on purpose: "3 aceitas" next to three
            // rows that show no verdict would announce the withheld one as
            // loudly as printing it.
            $acceptedCount = $submissions->filter(fn ($s) => in_array($s->result, ['Yes', 'AC', 'Accepted']))->count();
            $totalCount = $submissions->count();
        }

        return view('submissions', compact('submissions', 'acceptedCount', 'totalCount'));
    }

    /**
     * Show a single submission's detail (source code, judging output,
     * verdict). The list view's "Ver" action linked here but the route
     * never existed (404) -- see issue #30.
     */
    public function show(Run $run): View
    {
        $user = auth()->user();

        if (! $user->isAdmin() && ! $user->isJudge() && $run->user_id !== $user->user_id) {
            abort(403, 'Voce nao pode ver esta submissao.');
        }

        $run->load(['problem', 'language', 'answer', 'contest']);

        // Issue #138. The check above is "is this run yours"; this one is
        // "has the verdict been released". A team reaching its own run is
        // precisely the case the gate exists for, so the two are not the
        // same question and passing the first does not answer the second.
        $this->maskWithheldVerdict($run);

        // Issue #268 -- fonte binaria nao vai para dentro de `<pre>`.
        //
        // Isto lia o arquivo inteiro e a view o despejava num `{{ }}`. Um
        // `.sb3` e um ZIP: cada byte invalido em UTF-8 vira U+FFFD, e o
        // arquivo inteiro -- centenas de KB de lixo -- entra no HTML. Nao e
        // so feio: a pagina fica pesada, o leitor de tela le o lixo, e quem
        // queria VER o envio nao consegue.
        //
        // A deteccao e "o conteudo e UTF-8 valido e nao tem byte nulo", e
        // nao a extensao: a extensao vem do envio e mente quando alguem
        // renomeia. Binario ganha download em vez de render.
        $path = $run->getSourcePath();
        $sourceCode = null;
        $sourceIsBinary = false;
        $sourceBytes = null;

        if (file_exists($path)) {
            $conteudo = (string) file_get_contents($path);
            $sourceBytes = strlen($conteudo);
            $sourceIsBinary = ! self::isText($conteudo);
            $sourceCode = $sourceIsBinary ? null : $conteudo;
        }

        return view('submission-show', compact('run', 'sourceCode', 'sourceIsBinary', 'sourceBytes'));
    }

    /**
     * Issue #268 -- baixar o fonte, que e o unico jeito util quando ele e
     * binario.
     *
     * Mesma autorizacao do `show()`, e nao uma segunda: "quem pode ver este
     * envio" nao pode existir em duas versoes.
     */
    public function source(Run $run): Response
    {
        $user = auth()->user();

        if (! $user->isAdmin() && ! $user->isJudge() && $run->user_id !== $user->user_id) {
            abort(403, 'Voce nao pode ver esta submissao.');
        }

        $path = $run->getSourcePath();

        if (! file_exists($path)) {
            abort(404, 'Arquivo de codigo-fonte nao encontrado.');
        }

        // `octet-stream` sempre, mesmo para fonte de texto: o navegador nao
        // deve tentar interpretar o que um competidor enviou. Um `.html`
        // servido como `text/html` do proprio dominio seria XSS armazenado.
        return response()->download($path, basename((string) $run->filename), [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * UTF-8 valido e sem byte nulo.
     *
     * O byte nulo entra na conta porque ha binario que passa por UTF-8
     * valido por acaso -- e nenhum fonte de programa legitimo tem um.
     */
    private static function isText(string $content): bool
    {
        return $content === ''
            || (! str_contains($content, "\0") && mb_check_encoding($content, 'UTF-8'));
    }

    /**
     * Issue #138 -- the mask for a raw query-builder row.
     *
     * Controller::maskWithheldVerdict() takes a Run model; index() above
     * never builds one. Same fields, same reasoning: `result` is the
     * verdict, and `status` becomes `judging` rather than `judged` so the
     * row reads "Em avaliação" in partials/verdict.blade.php -- which is
     * true, because it is still waiting on the person who has to release
     * it.
     *
     * Staff looking at their own submissions keep the verdict, via the same
     * Run::verdictVisibleTo() rule every other path uses.
     */
    private function maskWithheldRow(object $submission): object
    {
        if (! $submission->contest_verification_required || $submission->verified_at !== null) {
            return $submission;
        }

        if (Run::viewerSeesWithheldVerdicts(auth()->user())) {
            return $submission;
        }

        $submission->result = null;
        $submission->status = 'judging';
        $submission->answer_id = null;
        $submission->judged_time = null;
        $submission->judge_id = null;
        $submission->auto_judge_result = null;
        $submission->auto_judge_stdout = null;
        $submission->auto_judge_stderr = null;

        return $submission;
    }
}
