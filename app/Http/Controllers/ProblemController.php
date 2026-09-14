<?php

namespace App\Http\Controllers;

use App\Models\Contest;
use App\Models\Problem;
use App\Models\Score;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class ProblemController extends Controller
{
    /**
     * Issue #137 -- what we are willing to serve out of a problem package's
     * description/ directory, and whether the browser may render it in place.
     *
     * The content type is decided by this table and never sniffed from the
     * file: the bytes come from an uploaded package, and letting the browser
     * guess is how a "statement" ends up being interpreted as something else.
     * The second field is the reason the table exists at all -- a PDF is safe
     * to show inline, but an HTML statement rendered inline runs its own
     * scripts on this application's origin, with the viewer's session; BOCA
     * packages do allow an HTML descfile, so those are handed over as a
     * download instead of being rendered. Anything unlisted is served as an
     * opaque download.
     */
    private const STATEMENT_TYPES = [
        'pdf' => ['application/pdf', true],
        'txt' => ['text/plain; charset=UTF-8', true],
        'png' => ['image/png', true],
        'jpg' => ['image/jpeg', true],
        'jpeg' => ['image/jpeg', true],
        'gif' => ['image/gif', true],
        'html' => ['text/html', false],
        'htm' => ['text/html', false],
        'ps' => ['application/postscript', false],
        'doc' => ['application/msword', false],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', false],
    ];

    /**
     * List the real (BOCA-schema) problems for the current user's contest,
     * replacing the old Exercise-table listing that had no relation to the
     * actual judging pipeline.
     */
    public function index()
    {
        $contest = $this->resolveContest();

        // Issue #135: show() has always applied a visibility rule -- a guest
        // sees a problem only when its contest is_public -- and index() did
        // not, so the listing handed every problem of the running contest to
        // anyone who asked, while the detail page for the same problem
        // returned 404. is_public defaults to false, so the listing was the
        // more permissive of the two by accident rather than by decision.
        //
        // The same rule, applied in one more place. A signed-in member of the
        // contest still sees their own problems either way.
        if ($contest && ! $this->mayList($contest)) {
            $contest = null;
        }

        $problems = $contest
            ? $contest->problems()->orderBy('short_name')->get()
            : collect();

        $solvedProblemIds = collect();
        if ($contest && auth()->check()) {
            $solvedProblemIds = Score::where('contest_id', $contest->id)
                ->where('user_id', auth()->id())
                ->where('is_solved', true)
                ->pluck('problem_id');
        }

        return view('exercises.index', [
            'contest' => $contest,
            'problems' => $problems,
            'solvedProblemIds' => $solvedProblemIds,
        ]);
    }

    public function show(Problem $problem)
    {
        $this->authorizeProblemVisibility($problem);

        // Issue #137: a problem imported from a BOCA package has its real
        // statement in description/, not in the plain-text `description`
        // column -- which is the normal import path, so the page used to tell
        // the whole field "o enunciado ainda nao foi disponibilizado" while
        // the PDF sat on the server. The view needs both answers: whether the
        // file exists at all, and whether this viewer may read it yet.
        return view('exercises.show', [
            'problem' => $problem,
            'hasStatementFile' => $problem->hasDescriptionFile(),
            'statementReleased' => $this->mayReadStatement($problem->contest),
        ]);
    }

    /**
     * Issue #137 -- serve the statement file itself to the team.
     *
     * Guarded twice on purpose, because the two questions are different:
     * authorizeProblemVisibility() is "may this viewer see this problem at
     * all" (the #135 rule, shared with the listing and the detail page), and
     * mayReadStatement() is "has the paper been handed out yet".
     */
    public function statement(Problem $problem): BinaryFileResponse
    {
        $this->authorizeProblemVisibility($problem);

        if (! $this->mayReadStatement($problem->contest)) {
            abort(404);
        }

        $path = $problem->getDescriptionFilePath();

        // The column can point at nothing: packages are imported per machine
        // while the database can be restored anywhere, so a row with a
        // description_file whose file never arrived is an ordinary state, not
        // a server fault. 404 -- the same answer as "this problem has no
        // statement file", which from outside is exactly what it is.
        if ($path === null || ! is_file($path)) {
            abort(404);
        }

        [$contentType, $inline] = self::STATEMENT_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))]
            ?? ['application/octet-stream', false];

        $response = response()->file($path, [
            'Content-Type' => $contentType,
            // Belt and braces with the table above: the declared type is the
            // only one the browser may act on, never one it inferred from the
            // bytes.
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // The name the team sees must describe the problem ("B-caixas.pdf"),
        // not the package's internal file name and certainly not the storage
        // path it came from -- response()->file() would otherwise disclose
        // whatever descfile happened to be called.
        $response->setContentDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $this->statementDownloadName($problem, $path)
        );

        return $response;
    }

    /**
     * A file name built from what the team already knows the problem by.
     */
    private function statementDownloadName(Problem $problem, string $path): string
    {
        $label = Str::slug(trim(($problem->short_name ? $problem->short_name.'-' : '').($problem->name ?? $problem->basename)));
        $label = $label !== '' ? $label : 'enunciado';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension !== '' ? "{$label}.{$extension}" : $label;
    }

    /**
     * The active public contest the current user belongs to (falling back to
     * the first active contest so admins/spectators without a contest_id can
     * still browse problems).
     */
    private function resolveContest(): ?Contest
    {
        $user = auth()->user();

        if ($user?->contest_id) {
            return Contest::find($user->contest_id);
        }

        // Issue #43: the technical practice contest is never "the active
        // contest" of an event (docs/specs/43-practice.md).
        return Contest::query()->competition()->where('is_active', true)->first();
    }

    /**
     * Issue #135 -- may the current viewer see this contest's problems?
     *
     * The single rule behind both the listing and the detail page, in this
     * order: staff always; then a signed-in member of that contest; then
     * everyone, but only when the contest is marked public.
     *
     * The middle branch requires a USER on purpose. It used to be "the
     * contest resolveContest() picked", which for a guest is the running
     * competition -- so being anonymous was as good as being in the contest.
     *
     * Issue #134 needed the same question answered on the token API, by four
     * controllers, so the rule moved to Contest::isVisibleTo() and this
     * delegates to it. Two copies of a visibility rule is how a listing and
     * a detail page come to disagree, which is the bug #135 was.
     *
     * One widening comes with the move: the model counts membership through
     * users.site_id -> sites.contest_id as well as users.contest_id, because
     * in the BOCA schema a user reaches a contest through either column. A
     * team registered against a site of this contest but with no direct
     * contest_id now sees its own problems here, which is what it was
     * already allowed to submit to.
     */
    private function mayList(Contest $contest): bool
    {
        return $contest->isVisibleTo(auth()->user());
    }

    /**
     * Issue #137 -- has this contest's statement been handed out yet?
     *
     * Deliberately a SECOND question on top of mayList(), not a second copy
     * of it: being allowed to see that problem B exists, is blue and is worth
     * solving is not the same as being allowed to read problem B's paper. A
     * contest can announce its problem list (or simply be public) before the
     * clock starts; a team that reads the statements an hour early is a team
     * that arrives with the solutions written, which is the one thing the
     * start time exists to prevent. So the file is released when the contest
     * clock starts, and stays released afterwards -- upsolving and public
     * archives are the point of keeping an old contest readable.
     *
     * A contest with no start_time has not been scheduled, so there is no
     * moment at which its paper became due; the honest reading of "not
     * started yet" is "not yet", and staff who want it readable have a start
     * time to set. Staff themselves are exempt: judges and admins write and
     * check these statements before the contest exists.
     *
     * Note this does NOT stop at the contest's end, and does not use
     * isRunning(): that also requires is_active, and deactivating a finished
     * contest must not retroactively confiscate its statements.
     */
    private function mayReadStatement(Contest $contest): bool
    {
        $user = auth()->user();

        if ($user?->isAdmin() || $user?->isJudge()) {
            return true;
        }

        return $contest->start_time !== null && now()->gte($contest->start_time);
    }

    /**
     * show() takes a Problem straight from route-model-binding on a route
     * with no auth middleware, so without this a problem from any other
     * (private, inactive, or not-yet-announced) contest was viewable just by
     * guessing/incrementing the numeric id.
     *
     * Issue #135: this used to let ANY viewer through for the contest
     * resolveContest() returns, and for a guest that is the running
     * competition -- so the active contest's problems were world-readable
     * whatever is_public said, which made the flag meaningless for exactly
     * the contest it matters most for. It now asks the same question
     * mayList() asks, so the listing and the detail page cannot disagree.
     */
    private function authorizeProblemVisibility(Problem $problem): void
    {
        if (! $this->mayList($problem->contest)) {
            abort(404);
        }
    }
}
