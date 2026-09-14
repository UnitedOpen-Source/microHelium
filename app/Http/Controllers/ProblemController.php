<?php

namespace App\Http\Controllers;

use App\Models\Contest;
use App\Models\Problem;
use App\Models\Score;

class ProblemController extends Controller
{
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

        return view('exercises.show', compact('problem'));
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
