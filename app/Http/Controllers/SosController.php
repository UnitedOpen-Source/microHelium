<?php

namespace App\Http\Controllers;

use App\Models\Contest;
use App\Models\ContestLog;
use App\Models\SosCall;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Issue #139 -- the team half of BOCA's S.O.S. (src/team/task.php).
 *
 * Same shape as PrintRequestController (#94), because it is the same kind of
 * thing: a team asking the organisation of its own site for something, with
 * the request landing in a queue those people actually watch. The difference
 * is what it is about. A clarification is about the *problem* and goes to the
 * jury; an S.O.S. is about the *team* -- a dead machine, a broken keyboard,
 * someone who needs a doctor -- and goes to whoever is standing in that room.
 *
 * Two things this does that the print request does not:
 *
 *   1. it requires an explicit confirmation, because BOCA does, and BOCA does
 *      because a stray click sends staff running across a gym; and
 *   2. it refuses to open a second call while the team's first one is still
 *      unresolved (see SosCall / the migration's unique index).
 */
class SosController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'role:team']);
    }

    public function create(): View
    {
        $contest = $this->contest();

        return view('sos', [
            'contest' => $contest,
            'noteMax' => $this->noteMax(),
            'openCall' => $contest ? $this->openCallFor($contest->id, auth()->id()) : null,
            'calls' => $contest
                ? SosCall::query()
                    ->where('contest_id', $contest->id)
                    ->where('user_id', auth()->id())
                    ->orderByDesc('id')
                    ->limit((int) config('sos.history_limit', 10))
                    ->get()
                : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $contest = $this->contest();

        // Unlike a print request, this is *not* gated on the contest being
        // mid-run. A team whose machine will not boot needs the staff during
        // setup, before the clock starts, and someone who faints during the
        // freeze does not become less unwell because the scoreboard stopped.
        // What it is gated on is having a contest and a site at all -- there
        // is no queue to land in otherwise.
        if (! $contest) {
            return back()->withErrors(['confirmation' => __('Sua conta não está associada a nenhuma competição.')]);
        }

        $request->validate([
            // BOCA's $_POST["confirmation"] == "confirm", kept literally.
            // The view renders it as a checkbox inside a disclosure, so
            // reaching this line takes two deliberate actions; the server
            // check is what makes that true for a hand-rolled POST as well.
            'confirmation' => ['required', 'in:confirm'],
            'note' => ['nullable', 'string', 'max:'.$this->noteMax()],
        ], [
            'confirmation.required' => __('Confirme o pedido antes de chamar a organização.'),
            'confirmation.in' => __('Confirme o pedido antes de chamar a organização.'),
            'note.max' => __('A observação deve ter no máximo :max caracteres.', ['max' => $this->noteMax()]),
        ]);

        $user = $request->user();

        // Same resolution order ClarificationController uses, and for the
        // same reason: the team's own site when it has one, and only a site
        // that belongs to THIS contest -- a site_id left over from another
        // contest would route the call to a room where nobody is looking for
        // this team, and would store a row whose contest_id and site_id
        // disagree about which event they belong to.
        $siteId = $contest->sites()->whereKey($user->site_id)->value('id')
            ?? $contest->sites()->value('id');

        if (! $siteId) {
            return back()->withErrors(['confirmation' => __('Sua conta não está associada a nenhuma sede.')]);
        }

        $note = trim((string) $request->input('note'));

        $call = DB::transaction(function () use ($contest, $siteId, $user, $note) {
            // Lock this team's rows before deciding, the same way run
            // numbering and the print queue lock before computing the next
            // number: without it two clicks 20ms apart both see "no open
            // call" and one of them dies on the unique index instead of
            // getting the friendly "ja existe" answer below.
            $existing = SosCall::query()
                ->where('contest_id', $contest->id)
                ->where('user_id', $user->user_id)
                ->unresolved()
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return SosCall::create([
                'contest_id' => $contest->id,
                'site_id' => $siteId,
                'user_id' => $user->user_id,
                'note' => $note !== '' ? $note : null,
                'status' => SosCall::STATUS_OPEN,
                'contest_time' => $contest->getContestTime(),
                'active_slot' => SosCall::ACTIVE_SLOT,
            ]);
        });

        // wasRecentlyCreated tells the two paths apart without a second
        // query, and without the controller having to re-derive "was there
        // already one?" outside the transaction that decided it.
        if (! $call->wasRecentlyCreated) {
            return redirect()->route('sos.create')
                ->with('info', __('Você já tem um chamado aberto. A organização da sua sede já foi avisada.'));
        }

        // Issue #88's screen is where the organisation watches the event go
        // by, so the call shows up there too -- as a warning, not an info:
        // an S.O.S. is not routine traffic. The team's own note is
        // deliberately NOT copied into the context; it is untrusted text and
        // the audit screen has no business rendering it. The id is here so
        // the log line points at the row that carries the state.
        ContestLog::log(
            $contest->id,
            'warning',
            "S.O.S. #{$call->id} raised by team {$user->username}",
            siteId: $siteId,
            userId: $user->user_id,
            context: ['sos_call_id' => $call->id, 'has_note' => $note !== ''],
        );

        return redirect()->route('sos.create')
            ->with('success', __('Chamado enviado. A organização da sua sede foi avisada.'));
    }

    private function noteMax(): int
    {
        return (int) config('sos.note_max_length', 200);
    }

    private function openCallFor(int $contestId, int $userId): ?SosCall
    {
        return SosCall::query()
            ->where('contest_id', $contestId)
            ->where('user_id', $userId)
            ->unresolved()
            ->first();
    }

    /**
     * Issue #43: the practice contest is not an event and has no staff room.
     */
    private function contest(): ?Contest
    {
        $user = auth()->user();

        return $user->contest_id ? Contest::query()->competition()->find($user->contest_id) : null;
    }
}
