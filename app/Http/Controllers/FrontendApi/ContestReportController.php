<?php

namespace App\Http\Controllers\FrontendApi;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\Site;
use App\Services\ContestReportBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issue #144 -- the data behind the two report screens BOCA has and this
 * application did not: src/staff/report/ (one site's submissions, verdicts
 * and timings) and src/judge/history.php (what each judge judged).
 *
 * Both are staff artefacts and both are unfrozen. That is the whole point of
 * them -- a site coordinator closing down their room, or a chief judge
 * reading a disputed verdict, needs the real numbers, not the ones the
 * public scoreboard is allowed to show. Which is exactly why the gate
 * matters: issue #134 found scoreboard/export handing unfrozen standings to
 * the wrong audience, and the way not to repeat it is that these routes are
 * never reachable by a team. The `role:` middleware in
 * routes/frontend_api_reports.php does the vertical half (who may call);
 * this class does the horizontal half (which contest, which site), because
 * a role check alone would let a coordinator at site B read site A's room by
 * editing a query string.
 *
 * Presentation lives in resources/js/features/SiteReport.vue and
 * JudgeHistory.vue; the charts are drawn there, client-side, rather than by
 * a bundled server-side charting library as BOCA does with libchart.
 */
class ContestReportController extends Controller
{
    public function __construct(private ContestReportBuilder $builder) {}

    /**
     * GET /api/frontend/reports/site
     *
     * Audience: admin, staff, site coordinator.
     */
    public function site(Request $request): JsonResponse
    {
        $contest = $this->resolveContest($request);
        $siteId = $this->resolveSiteId($request, $contest);

        return $this->respond([
            'contest' => $this->contestPayload($contest),
            'scope' => [
                'site_id' => $siteId,
                'site_name' => $siteId === null ? null : Site::find($siteId)?->name,
                'sites' => $this->selectableSites($contest),
                // Presentation only -- the server decides the scope above
                // regardless of what the client does with this flag.
                'can_choose_site' => $this->seesEverySite(),
            ],
        ] + $this->builder->siteReport($contest, $siteId));
    }

    /**
     * GET /api/frontend/reports/judge-history
     *
     * Audience: admin and judge. Not staff and not the site coordinator: a
     * verdict is the jury's, and BOCA files this screen under judge/ for the
     * same reason. It also carries every team name next to every verdict,
     * unfrozen, which is a jury-sized amount of trust.
     */
    public function judgeHistory(Request $request): JsonResponse
    {
        $contest = $this->resolveContest($request);
        $user = auth()->user();

        // The same scope JudgeController::index() puts on the judging queue:
        // a judge stationed at a site sees their own site plus whatever
        // Backend\SiteController explicitly routed to them. A judge with no
        // site, and an admin, keep the contest-wide view. ->site can be null
        // even with site_id set (the site was soft-deleted), which is why
        // both are checked.
        $siteIds = null;

        if (! $user->isAdmin() && $user->site_id && $user->site) {
            $siteIds = $user->site->routedJudgingSiteIds();
        }

        $judgeId = $request->integer('judge_id') ?: null;

        return $this->respond([
            'contest' => $this->contestPayload($contest),
            'scope' => [
                'judge_id' => $judgeId,
                'site_ids' => $siteIds,
            ],
        ] + $this->builder->judgeHistory($contest, $siteIds, $judgeId, max(1, (int) $request->integer('page', 1))));
    }

    /**
     * Which contest is being reported on.
     *
     * A report is worth reading after the event, so an admin may name a
     * contest that is no longer the active one. Nobody else may: for a
     * judge, a staff member or a coordinator the answer is their own
     * contest, and a contest_id naming any other one is refused rather than
     * quietly ignored -- silently reporting on contest A when the URL said B
     * is how someone ends up trusting the wrong numbers.
     */
    private function resolveContest(Request $request): Contest
    {
        $user = auth()->user();
        $requested = $request->integer('contest_id') ?: null;

        if ($requested !== null && ! $user->isAdmin() && (int) $user->contest_id !== $requested) {
            abort(403, 'Voce nao pode ver relatorios de outro contest.');
        }

        $contest = match (true) {
            $requested !== null => Contest::find($requested),
            (bool) $user->contest_id => Contest::find($user->contest_id),
            // Issue #43: the technical practice contest is never "the active
            // contest" of an event.
            default => Contest::query()->competition()->where('is_active', true)->first(),
        };

        // Issue #43 again, for a contest named explicitly: practice has no
        // sites, no jury and no report, and must not be reachable through an
        // event-shaped surface. 404 rather than 403, matching
        // IcpcReportController.
        if (! $contest || $contest->is_practice) {
            abort(404, 'Competicao nao encontrada.');
        }

        return $contest;
    }

    /**
     * Which site the per-site report covers. null means every site, which is
     * the admin view and BOCA's src/admin/report.php.
     *
     * A staff account with no site at all also gets the contest-wide view --
     * that is already how StaffController::tasks() and SosQueueController
     * behave, and splitting the convention on this one screen would be worse
     * than either answer.
     */
    private function resolveSiteId(Request $request, Contest $contest): ?int
    {
        $requested = $request->integer('site_id') ?: null;

        if ($requested !== null && ! Site::where('contest_id', $contest->id)->whereKey($requested)->exists()) {
            // Not 403: an id that names no site of this contest is a
            // not-found, and answering 403 would confirm that the id names a
            // real site somewhere else.
            abort(404, 'Sede nao encontrada nesta competicao.');
        }

        if ($this->seesEverySite()) {
            return $requested;
        }

        $own = (int) auth()->user()->site_id;

        if ($requested !== null && $requested !== $own) {
            abort(403, 'Voce nao pode ver o relatorio de outra sede.');
        }

        return $own;
    }

    /**
     * True for an admin, and for a staff/coordinator account that is not
     * attached to any site. Everyone else is pinned to their own room.
     */
    private function seesEverySite(): bool
    {
        $user = auth()->user();

        return $user->isAdmin() || ! $user->site_id;
    }

    /**
     * The sites the caller may switch between. A coordinator gets exactly
     * one entry -- their own -- so the UI has a name to print rather than an
     * id, and so the list itself never becomes a directory of every room in
     * the competition for someone who may only see one.
     *
     * @return list<array{id:int,name:string}>
     */
    private function selectableSites(Contest $contest): array
    {
        $query = Site::query()->where('contest_id', $contest->id)->orderBy('name');

        if (! $this->seesEverySite()) {
            $query->whereKey((int) auth()->user()->site_id);
        }

        return $query->get(['id', 'name'])
            ->map(fn (Site $site) => ['id' => (int) $site->id, 'name' => (string) $site->name])
            ->all();
    }

    private function contestPayload(Contest $contest): array
    {
        return [
            'id' => (int) $contest->id,
            'name' => (string) $contest->name,
            'duration_minutes' => (int) $contest->duration,
            'is_running' => $contest->isRunning(),
            // Reported, not enforced: the audience of this screen is meant
            // to see through the freeze. The flag is here so the page can
            // say so out loud instead of leaving the reader to guess whether
            // what they are looking at is what the teams can see.
            'is_frozen' => $contest->isFrozen(),
        ];
    }

    private function respond(array $data): JsonResponse
    {
        return response()->json(['data' => ['generated_at' => now()->toIso8601String()] + $data])
            // Unfrozen, scoped to one person's authorization. Nothing about
            // it should sit in a shared cache.
            ->header('Cache-Control', 'no-store, private');
    }
}
