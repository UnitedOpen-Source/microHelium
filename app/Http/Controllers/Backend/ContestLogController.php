<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\ContestLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Issue #88 -- the contest audit log, readable at last.
 *
 * ContestLog was written in eleven places and read in none: no route, no
 * screen, no endpoint. The table grew during a contest and nobody could
 * look at it. BOCA has src/admin/log.php for exactly this, and it is the
 * tool you reach for when a team disputes a verdict -- the moment nobody
 * wants to be opening a database by hand.
 */
class ContestLogController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();

        $contests = $isAdmin
            // Issue #43: practice is not an event; its log is not contest
            // history anyone is auditing here.
            ? Contest::query()->competition()->orderByDesc('created_at')->get(['id', 'name'])
            : Contest::query()->competition()->whereKey($user->contest_id)->get(['id', 'name']);

        $contest = $this->resolveContest($request, $contests, $isAdmin);

        $query = ContestLog::query()
            ->when($contest, fn ($q) => $q->where('contest_id', $contest->id))
            // A judge or staff member with no contest would otherwise see
            // every contest's log; there is no such thing as "all contests"
            // for them.
            ->when(! $contest, fn ($q) => $q->whereRaw('1 = 0'))
            ->with(['user:user_id,fullname,username', 'site:id,name'])
            ->orderByDesc('id');

        $type = $request->query('type');
        if (is_string($type) && in_array($type, ['error', 'warning', 'info', 'debug'], true)) {
            $query->where('type', $type);
        }

        $search = $request->query('q');
        if (is_string($search) && trim($search) !== '') {
            $query->where('message', 'like', '%'.trim($search).'%');
        }

        foreach (['from' => '>=', 'to' => '<='] as $param => $operator) {
            $value = $request->query($param);
            if (is_string($value) && $value !== '' && strtotime($value) !== false) {
                $query->where('created_at', $operator, $param === 'to'
                    ? date('Y-m-d 23:59:59', strtotime($value))
                    : date('Y-m-d 00:00:00', strtotime($value)));
            }
        }

        $logs = $query->paginate(self::PER_PAGE)->withQueryString();

        return view('backend.logs', [
            'logs' => $logs,
            'contests' => $contests,
            'contest' => $contest,
            'isAdmin' => $isAdmin,
            'filters' => [
                'type' => is_string($type) ? $type : '',
                'q' => is_string($search) ? $search : '',
                'from' => is_string($request->query('from')) ? $request->query('from') : '',
                'to' => is_string($request->query('to')) ? $request->query('to') : '',
            ],
        ]);
    }

    /**
     * A judge or staff member is pinned to their own contest whatever the
     * query string says; only an admin may pick.
     */
    private function resolveContest(Request $request, $contests, bool $isAdmin): ?Contest
    {
        if (! $isAdmin) {
            return $contests->first();
        }

        $requested = $request->query('contest_id');

        if (is_scalar($requested) && $requested !== '') {
            return $contests->firstWhere('id', (int) $requested);
        }

        return $contests->first();
    }
}
