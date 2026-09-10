<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\Site;
use App\Models\SiteJudgingRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Site management -- the prerequisite for multi-site coordination (issue
 * #18). Before this, ContestWizardController::store() hardcoded exactly one
 * "Main Site" per contest and there was no way to create, edit, or
 * configure additional physical sites at all.
 */
class SiteController extends Controller
{
    public function index(Request $request): View
    {
        $contests = Contest::orderByDesc('created_at')->get();

        $contestId = (int) $request->query('contest_id', 0);
        $contest = ($contestId ? $contests->firstWhere('id', $contestId) : null)
            ?? $contests->first();

        $sites = collect();
        if ($contest) {
            $sites = Site::where('contest_id', $contest->id)->orderBy('name')->get();
        }

        return view('backend.sites', [
            'contests' => $contests,
            'contest' => $contest,
            'sites' => $sites,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'contest_id' => 'required|exists:contests,id',
            'name' => 'required|string|max:100',
            'ip_address' => 'nullable|string|max:200',
            'chief_judge_name' => 'nullable|string|max:50',
            'score_visibility' => 'required|in:all,own_site',
            'max_judge_wait_time' => 'required|integer|min:60',
            'judging_routes' => 'array',
            'judging_routes.*' => 'integer|exists:sites,id',
        ]);

        $site = DB::transaction(function () use ($validated) {
            $site = Site::create([
                'contest_id' => $validated['contest_id'],
                'name' => $validated['name'],
                'ip_address' => $validated['ip_address'] ?? null,
                'chief_judge_name' => $validated['chief_judge_name'] ?? null,
                'score_visibility' => $validated['score_visibility'],
                'max_judge_wait_time' => $validated['max_judge_wait_time'],
                'is_active' => true,
                'permit_logins' => true,
            ]);

            $this->syncJudgingRoutes($site, $validated['judging_routes'] ?? []);

            return $site;
        });

        return redirect()->route('backend.sites', ['contest_id' => $site->contest_id])
            ->with('success', "Site \"{$site->name}\" criado com sucesso!");
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'ip_address' => 'nullable|string|max:200',
            'chief_judge_name' => 'nullable|string|max:50',
            'score_visibility' => 'required|in:all,own_site',
            'max_judge_wait_time' => 'required|integer|min:60',
            'is_active' => 'nullable|boolean',
            'judging_routes' => 'array',
            'judging_routes.*' => 'integer|exists:sites,id',
        ]);

        DB::transaction(function () use ($site, $validated) {
            $site->update([
                'name' => $validated['name'],
                'ip_address' => $validated['ip_address'] ?? null,
                'chief_judge_name' => $validated['chief_judge_name'] ?? null,
                'score_visibility' => $validated['score_visibility'],
                'max_judge_wait_time' => $validated['max_judge_wait_time'],
                'is_active' => (bool) ($validated['is_active'] ?? false),
            ]);

            $this->syncJudgingRoutes($site, $validated['judging_routes'] ?? []);
        });

        return redirect()->route('backend.sites', ['contest_id' => $site->contest_id])
            ->with('success', "Site \"{$site->name}\" atualizado com sucesso!");
    }

    public function destroy(Site $site): RedirectResponse
    {
        $contestId = $site->contest_id;
        $name = $site->name;
        $site->delete();

        return redirect()->route('backend.sites', ['contest_id' => $contestId])
            ->with('success', "Site \"{$name}\" removido.");
    }

    /**
     * A source site can never route to itself -- routing from a site to
     * itself would be a meaningless no-op entry that only clutters the
     * table and confuses routedJudgingSiteIds() (which already always
     * includes the host's own id).
     */
    private function syncJudgingRoutes(Site $site, array $sourceSiteIds): void
    {
        SiteJudgingRoute::where('host_site_id', $site->id)->delete();

        $sourceSiteIds = array_values(array_unique(array_filter($sourceSiteIds, fn ($id) => (int) $id !== $site->id)));

        foreach ($sourceSiteIds as $sourceSiteId) {
            SiteJudgingRoute::create([
                'host_site_id' => $site->id,
                'source_site_id' => $sourceSiteId,
            ]);
        }
    }
}
