<?php

namespace App\Http\Controllers\FrontendApi;

use App\Http\Controllers\Controller;
use App\Models\Judgehost;
use App\Models\Run;
use App\Services\JudgeWorkQueue;
use App\Support\IdempotencyStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * Issue #53, phase 4 -- the machines, visible and manageable.
 *
 * Phases 1 to 3 built the protocol: a machine registers, pulls work, holds
 * a lease, heartbeats, reports, gives back. None of it was visible to the
 * person running the contest. Today the only way to add a judge machine is
 * `php artisan judgehost:create` on the server, and the only way to see
 * whether one is alive is to read the runs table. An organiser at 8am on
 * the day of a regional does not have a shell on that box.
 *
 * The spec (docs/specs/53-distributed-judging.md) is explicit that the API
 * and the `can_manage_judges` capability come BEFORE any UI action, so this
 * is the backend half on its own; docs/specs/53-judge-management.md is the
 * contract the interface is built against.
 *
 * Session guard + CSRF, like every other /api/frontend/* surface (see
 * docs/specs/README.md), not routes/api.php's bearer-token contract. The
 * judge machines themselves speak a different protocol entirely, on
 * routes/judgehost.php with their own guard.
 */
class JudgehostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $hosts = Judgehost::query()
            ->with('capabilities')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        // One query for every host's held runs rather than one per host:
        // this page is refreshed during a contest, and a judge pool is the
        // kind of thing someone leaves open on a second monitor.
        $held = Run::query()
            ->whereIn('judgehost_id', $hosts->pluck('id'))
            ->where('status', 'judging')
            ->with('problem:id,short_name')
            ->get()
            ->groupBy('judgehost_id');

        $items = $hosts->map(fn (Judgehost $host) => $this->describe($host, $held->get($host->id)))->values();

        return response()->json([
            'data' => [
                'items' => $items->all(),
                'meta' => [
                    'total' => $items->count(),
                    'enabled' => $items->where('enabled', true)->count(),
                    'judging' => $items->where('state', 'judging')->count(),
                    // The number worth alarming on: a machine that was
                    // registered and has stopped answering.
                    'stale' => $items->where('state', 'stale')->count(),
                    'lease_seconds' => (int) config('judgehost.lease_seconds', 600),
                    'stale_after_seconds' => $this->staleAfter(),
                ],
                'capabilities' => [
                    // Published here because the spec requires the
                    // capability to exist before the interface grows a
                    // button. It is true for everyone who gets past the
                    // route's own `admin` middleware, which is the whole
                    // audience of this endpoint.
                    'can_manage_judges' => (bool) $request->user()?->isAdmin(),
                ],
            ],
        ]);
    }

    /**
     * Issue a credential for a new machine.
     *
     * The web equivalent of `php artisan judgehost:create`, and the same
     * contract: the token is shown exactly once and the database keeps only
     * its sha256, so nobody -- staff included -- can read it back.
     */
    public function store(Request $request): JsonResponse
    {
        $request->merge(['name' => trim((string) $request->input('name', ''))]);

        // The raw token must never be persisted, and IdempotencyStore
        // persists exactly the response body its callback returns so a
        // retry replays it. Same resolution as
        // Frontend\WebcastController::storeCredential(): the callback
        // always returns token:null, and the real token is overlaid
        // afterwards for the caller that actually issued it -- a replay
        // gets the redacted body, which is correct, because a token that
        // could be fetched twice is a token that can be fetched by someone
        // who was not there the first time.
        $issued = null;

        $response = IdempotencyStore::handle($request, 'POST /api/frontend/judgehosts', function () use ($request, &$issued) {
            Validator::make($request->all(), [
                'name' => ['required', 'string', 'min:1', 'max:100'],
            ])->validate();

            [$host, $token] = Judgehost::issue($request->input('name'), $request->user()?->user_id);

            $issued = $token;

            return response()->json([
                'data' => [
                    'judgehost' => $this->describe($host->load('capabilities'), null),
                    'token' => null,
                ],
            ], 201);
        });

        if ($issued === null) {
            return $response;
        }

        $payload = $response->getData(true);
        $payload['data']['token'] = $issued;

        return response()->json($payload, $response->getStatusCode());
    }

    /**
     * Enable or disable a machine.
     *
     * `enabled` is the kill switch: Judgehost::authenticate() refuses a
     * disabled host, so it stops being able to fetch work, report a
     * verdict or heartbeat from the very next request.
     *
     * Disabling therefore has to give its runs back, and that is the part
     * worth being careful about. Without it, whatever the machine was
     * holding stays in `judging` with a dead holder until the lease expires
     * -- ten minutes by default -- and ten minutes of a contest is a long
     * time to wait for a run that nobody is judging. Handing it back
     * immediately is safe precisely because the host can no longer
     * authenticate: the fencing token from #123 refuses its report even if
     * the process is still running and finishes.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        return IdempotencyStore::handle($request, 'PATCH /api/frontend/judgehosts/'.$id, function () use ($request, $id) {
            Validator::make($request->all(), [
                'enabled' => ['required', 'boolean'],
            ])->validate();

            $host = Judgehost::with('capabilities')->find($id);

            if (! $host) {
                return response()->json(['message' => 'Maquina de julgamento nao encontrada.'], 404);
            }

            $enabled = $request->boolean('enabled');
            $released = 0;

            if (! $enabled && $host->enabled) {
                $released = app(JudgeWorkQueue::class)->giveBack($host);
            }

            $host->update(['enabled' => $enabled]);

            return response()->json([
                'data' => [
                    'judgehost' => $this->describe($host->fresh('capabilities'), null),
                    // Named in the response because it is the number the
                    // person who just pressed the button needs to see: how
                    // many runs went back into the queue because of it.
                    'released_runs' => $released,
                ],
            ]);
        });
    }

    /**
     * @param  Collection<int, Run>|null  $held
     * @return array<string, mixed>
     */
    private function describe(Judgehost $host, $held): array
    {
        $held ??= collect();

        return [
            'id' => $host->id,
            'name' => $host->name,
            'enabled' => (bool) $host->enabled,
            'state' => $this->state($host, $held->isNotEmpty()),
            'cpu_count' => $host->cpu_count,
            'memory_mb' => $host->memory_mb,
            'last_seen_at' => $host->last_seen_at?->toISOString(),
            'seconds_since_seen' => $host->last_seen_at ? (int) $host->last_seen_at->diffInSeconds(now()) : null,
            // What the machine said it can run (#117). Empty means "has
            // declared nothing", which the queue treats as "can judge
            // anything" -- an agent older than #117 behaves that way, and
            // the interface must not render that as "can judge nothing".
            'languages' => $host->capabilities->pluck('extension')->sort()->values()->all(),
            'declares_languages' => $host->capabilities->isNotEmpty(),
            'holding' => $held->map(fn (Run $run) => [
                'run_id' => $run->id,
                'run_number' => $run->run_number,
                'problem' => $run->problem?->short_name,
                'claimed_at' => $run->claimed_at?->toISOString(),
                'seconds_held' => $run->claimed_at ? (int) $run->claimed_at->diffInSeconds(now()) : null,
            ])->values()->all(),
            'created_at' => $host->created_at?->toISOString(),
        ];
    }

    /**
     * Disabled, never seen, judging, stale, or idle -- in that order,
     * because they are not mutually exclusive and the order is the answer
     * to "what should the operator look at first".
     */
    private function state(Judgehost $host, bool $isHolding): string
    {
        if (! $host->enabled) {
            return 'disabled';
        }

        if ($host->last_seen_at === null) {
            return 'never_seen';
        }

        if ($isHolding) {
            return 'judging';
        }

        return $host->last_seen_at->lt(now()->subSeconds($this->staleAfter())) ? 'stale' : 'idle';
    }

    /**
     * How long a machine may go quiet before it is worth showing as stale.
     *
     * Derived from the agent's own backoff rather than from the lease: an
     * idle agent asks for work at most every poll_max_seconds (30s by
     * default), so anything past a few of those means it is not asking at
     * all. Using the lease here instead -- ten minutes -- would call a
     * dead machine healthy for the whole of a short contest.
     */
    private function staleAfter(): int
    {
        return max(60, (int) round(3 * (float) config('judgehost.agent.poll_max_seconds', 30)));
    }
}
