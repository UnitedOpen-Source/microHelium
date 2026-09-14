<?php

namespace App\Http\Controllers;

use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\SosCall;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Issue #139 -- the staff half of the S.O.S.
 *
 * A screen of its own rather than more rows on StaffController::tasks().
 * The task queue is balloons and printouts, worked through in order; this is
 * the list you glance at to see whether anyone in the room is stuck, and its
 * two actions ("estou indo" / "resolvido") are not the task queue's single
 * "Marcar concluida".
 *
 * Audience: the people physically present at the site. That is the staff
 * (user_type=staff) and the site coordinator (user_type=site), who is the
 * local organisation and already answers that site's clarifications and
 * works its task queue -- plus admins, as everywhere. Deliberately NOT
 * judges: a judge rules on problems, and BOCA routes the S.O.S. to the site,
 * not to the jury. That distinction is the whole point of the issue.
 */
class SosQueueController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'role:staff,site,admin']);
    }

    public function index(): View
    {
        $contest = $this->resolveContest();
        $user = auth()->user();

        $calls = $contest
            ? SosCall::query()
                ->where('contest_id', $contest->id)
                // Issue #87's rule, for the same physical reason: a staff
                // member assigned to a site answers calls from that room,
                // not from a room three hundred kilometres away. An admin,
                // and a staff account with no site at all, still see
                // everything -- that is how StaffController::tasks() already
                // behaves and splitting the convention here would be worse
                // than either answer.
                ->when($user->site_id && ! $user->isAdmin(), fn ($query) => $query->where('site_id', $user->site_id))
                ->with(['user:user_id,fullname,username', 'site:id,name', 'acknowledgedBy:user_id,fullname,username', 'resolvedBy:user_id,fullname,username'])
                // Open first, then acknowledged, then resolved: the states
                // sort alphabetically in the wrong order, so it is spelled
                // out rather than left to `orderBy('status')`.
                ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'acknowledged' THEN 1 ELSE 2 END")
                ->orderByDesc('id')
                ->get()
            : collect();

        return view('staff.sos', [
            'contest' => $contest,
            'calls' => $calls,
            'openCount' => $calls->where('status', SosCall::STATUS_OPEN)->count(),
        ]);
    }

    public function acknowledge(SosCall $sosCall): RedirectResponse
    {
        $this->authorizeCallAccess($sosCall);

        if (! $sosCall->acknowledge(auth()->user())) {
            return redirect()->route('staff.sos')
                ->with('error', 'Esse chamado ja foi atendido ou resolvido.');
        }

        $this->log($sosCall, 'acknowledged');

        return redirect()->route('staff.sos')->with('success', 'Chamado marcado como em atendimento.');
    }

    public function resolve(SosCall $sosCall): RedirectResponse
    {
        $this->authorizeCallAccess($sosCall);

        if (! $sosCall->resolve(auth()->user())) {
            return redirect()->route('staff.sos')->with('error', 'Esse chamado ja foi resolvido.');
        }

        $this->log($sosCall, 'resolved');

        return redirect()->route('staff.sos')->with('success', 'Chamado resolvido.');
    }

    private function log(SosCall $call, string $what): void
    {
        $staff = auth()->user();

        ContestLog::log(
            $call->contest_id,
            'info',
            "S.O.S. #{$call->id} {$what} by {$staff->username}",
            siteId: $call->site_id,
            userId: $staff->user_id,
            context: ['sos_call_id' => $call->id, 'status' => $call->status],
        );
    }

    private function resolveContest(): ?Contest
    {
        $user = auth()->user();

        if ($user->contest_id) {
            return Contest::find($user->contest_id);
        }

        // Issue #43: practice has no staff room.
        return Contest::query()->competition()->where('is_active', true)->first();
    }

    /**
     * The listing scopes by contest and site, so the two actions have to as
     * well. Without this, a staff member at site B could acknowledge (and,
     * worse, resolve) site A's emergency by incrementing an id -- which is
     * not a permissions abstraction but a team at site A whose call is now
     * marked handled by somebody who is not in the building.
     */
    private function authorizeCallAccess(SosCall $call): void
    {
        $user = auth()->user();

        $this->authorizeScopedAccess(
            $user->contest_id,
            $call->contest_id,
            'Voce nao pode atender chamados de outro contest.'
        );

        if ($user->site_id && ! $user->isAdmin() && $call->site_id !== $user->site_id) {
            abort(403, 'Voce nao pode atender chamados de outra sede.');
        }
    }
}
