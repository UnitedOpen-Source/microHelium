<?php

namespace App\Http\Controllers\FrontendApi;

use App\Console\Commands\ReconcileStuckRunsCommand;
use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\Run;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Issue #191 -- the screen that was in the menu with nothing behind it.
 *
 * `/judge/health` answered 200 and `/api/frontend/judging-health` answered
 * 404, so the page rendered the "this resource is not available yet"
 * message from resources/js/features/api.js. It was the only one of sixteen
 * feature endpoints that did not resolve. The watchdog itself has worked
 * since #45; nobody could see what it was doing.
 *
 * What this deliberately does NOT do is invent data. The spec
 * (docs/specs/45-watchdog.md) is explicit that `last_judge_activity_at`,
 * lease tokens and requeue timestamps are proposed extensions that the Run
 * model does not have, and that the backend must
 * "omitir capacidades/retornar null sem inventar atividade". So
 * `last_activity_at` and `next_check_at` come back null rather than being
 * derived from something that only looks like activity -- a screen that
 * confidently shows a wrong "last seen" is worse than one that says it does
 * not know.
 */
class JudgingHealthController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * The presentation states, which are independent of the verdict.
     * "Recuperada" means finished after a retry, not necessarily accepted.
     */
    private const STATES = ['overdue', 'retrying', 'recovered', 'failed', 'pending', 'judging'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $contest = Contest::where('is_active', true)->where('is_practice', false)->first();

        if (! $contest) {
            return response()->json(['data' => $this->empty()]);
        }

        // The horizon the spec asks to be defined before materialising
        // metrics: this contest's runs. `summary` counts all of them
        // regardless of the status filter, which is why the filter is
        // applied after the tally and not inside the query.
        $runs = Run::query()
            ->where('contest_id', $contest->id)
            ->when($this->siteScope($user) !== null, fn ($q) => $q->whereIn('site_id', $this->siteScope($user)))
            ->with([
                'problem:id,short_name,name',
                'site:id,name,max_judge_wait_time',
                'user:user_id,fullname',
                'answer:id,short_name',
            ])
            ->orderByDesc('created_at')
            ->get();

        $described = $runs->map(fn (Run $run) => $this->describe($run, $user))
            ->filter(fn (array $row) => $row['recovery_status'] !== null)
            ->values();

        $summary = collect(['overdue', 'retrying', 'recovered', 'failed'])
            ->mapWithKeys(fn (string $state) => [$state => $described->where('recovery_status', $state)->count()])
            ->all();

        $status = (string) $request->query('status', '');
        $visible = in_array($status, self::STATES, true)
            ? $described->where('recovery_status', $status)->values()
            : $described;

        $page = max(1, (int) $request->query('page', 1));
        $lastPage = max(1, (int) ceil($visible->count() / self::PER_PAGE));

        return response()->json([
            'data' => [
                'generated_at' => now()->toISOString(),
                'watchdog' => $this->watchdog(),
                'summary' => $summary,
                'items' => $visible->forPage($page, self::PER_PAGE)->values()->all(),
                'meta' => [
                    'current_page' => min($page, $lastPage),
                    'last_page' => $lastPage,
                    'total' => $visible->count(),
                ],
            ],
        ]);
    }

    /**
     * Whether automatic recovery is actually happening.
     *
     * `enabled` is not "is the command registered" -- it is "did it run
     * recently enough to be doing its job". A scheduler that stopped on
     * Tuesday leaves every screen looking exactly like a contest where
     * nothing is stuck, and that is the failure this answers.
     *
     * @return array{enabled: bool, last_checked_at: string|null}
     */
    private function watchdog(): array
    {
        $last = Cache::get(ReconcileStuckRunsCommand::LAST_RUN_KEY);
        $at = is_string($last) ? Carbon::parse($last) : null;

        return [
            // Three times the five-minute schedule: one missed tick is a
            // slow machine, three is something wrong.
            'enabled' => $at !== null && $at->gt(now()->subMinutes(15)),
            'last_checked_at' => $at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Run $run, $user): array
    {
        $attempts = (int) $run->reconcile_attempts;

        return [
            'id' => $run->id,
            'problem_name' => $run->problem?->short_name ?? $run->problem?->name,
            'team_name' => $run->user?->fullname,
            'site_name' => $run->site?->name,
            'max_wait_seconds' => (int) ($run->site?->max_judge_wait_time ?? 900),
            'retry_count' => $attempts,
            'recovery_status' => $this->state($run, $attempts),
            // Null on purpose -- see the class docblock. Run carries no
            // activity timestamp, and deriving one from created_at would be
            // presenting an assumption as a measurement.
            'last_activity_at' => null,
            'next_check_at' => null,
            'message' => $this->message($run, $attempts),
            'detail_url' => $this->detailUrl($run, $user),
        ];
    }

    private function state(Run $run, int $attempts): ?string
    {
        if (in_array($run->status, ['pending', 'judging'], true)) {
            if ($run->isOverdue()) {
                return $attempts > 0 ? 'retrying' : 'overdue';
            }

            return $run->status;
        }

        if ($run->status !== 'judged' || $attempts === 0) {
            // A run judged normally is not part of the recovery story, and
            // listing it would bury the handful that are.
            return null;
        }

        return $run->answer?->short_name === 'CS' ? 'failed' : 'recovered';
    }

    private function message(Run $run, int $attempts): ?string
    {
        return match ($this->state($run, $attempts)) {
            'overdue' => 'Sem atividade alem do limite da sede. A recuperacao automatica deve tentar na proxima verificacao.',
            'retrying' => 'Uma nova tentativa ja foi despachada. O limite e uma retentativa por envio.',
            'recovered' => 'Concluido apos retentativa.',
            'failed' => 'A recuperacao desistiu e o envio foi encerrado como erro de julgamento.',
            default => null,
        };
    }

    /**
     * Only when this profile could already open the run. The link must not
     * be the thing that widens access.
     */
    private function detailUrl(Run $run, $user): ?string
    {
        return ($user?->isAdmin() || $user?->isJudge()) ? '/submission/'.$run->id : null;
    }

    /**
     * Site ids this viewer may see, or null for "everything".
     *
     * Same rule as JudgeController::index(): a judge stationed at a site
     * sees their own site plus whatever was routed to them (#41); an admin
     * sees the contest.
     *
     * @return list<int>|null
     */
    private function siteScope($user): ?array
    {
        if ($user?->isAdmin()) {
            return null;
        }

        if ($user?->site_id && $user->site) {
            return $user->site->routedJudgingSiteIds();
        }

        return $user?->site_id ? [(int) $user->site_id] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(): array
    {
        return [
            'generated_at' => now()->toISOString(),
            'watchdog' => $this->watchdog(),
            'summary' => ['overdue' => 0, 'retrying' => 0, 'recovered' => 0, 'failed' => 0],
            'items' => [],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0],
        ];
    }
}
