<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\Site;
use App\Models\SiteJudgingRoute;
use Helium\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
            // sites.blade.php reads $site->judgingRoutes for every row (list
            // + edit modal) -- without eager loading that's an N+1 query.
            $sites = Site::where('contest_id', $contest->id)->with('judgingRoutes')->orderBy('name')->get();
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
            'ip_address' => ['nullable', 'string', 'max:200', function ($attribute, $value, $fail) { $this->validateIpAddressField($attribute, $value, $fail); }],
            'chief_judge_name' => 'nullable|string|max:50',
            'score_visibility' => 'required|in:all,own_site',
            'max_judge_wait_time' => 'required|integer|min:60',
            'judging_routes' => 'array',
            // Scoped to the same contest (a route across contests would
            // corrupt the routing model) and to non-deleted sites --
            // Laravel's exists rule queries the raw table, so a soft-deleted
            // site's id would otherwise still pass.
            'judging_routes.*' => [
                'integer',
                Rule::exists('sites', 'id')->where('contest_id', $request->input('contest_id'))->whereNull('deleted_at'),
            ],
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
            'ip_address' => ['nullable', 'string', 'max:200', function ($attribute, $value, $fail) { $this->validateIpAddressField($attribute, $value, $fail); }],
            'chief_judge_name' => 'nullable|string|max:50',
            'score_visibility' => 'required|in:all,own_site',
            'max_judge_wait_time' => 'required|integer|min:60',
            'is_active' => 'nullable|boolean',
            'judging_routes' => 'array',
            'judging_routes.*' => [
                'integer',
                Rule::exists('sites', 'id')->where('contest_id', $site->contest_id)->whereNull('deleted_at'),
            ],
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

        // Site uses SoftDeletes, so the users.site_id / site_judging_routes
        // foreign keys' nullOnDelete()/cascadeOnDelete() never fire (those
        // only trigger on a real row DELETE) -- without this, users and
        // judging routes are left pointing at a now-trashed site, which
        // crashes JudgeController the next time that judge loads /judge/runs.
        DB::transaction(function () use ($site) {
            User::where('site_id', $site->id)->update(['site_id' => null]);
            SiteJudgingRoute::where('host_site_id', $site->id)->orWhere('source_site_id', $site->id)->delete();
            $site->delete();
        });

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

    /**
     * ip_address became load-bearing for login access in issue #50 -- a
     * typo'd octet or an invalid CIDR suffix (e.g. "/33") would previously
     * save silently and just never match any real client IP, locking out
     * every user of that site with no warning at save time.
     */
    public function validateIpAddressField(string $attribute, mixed $value, \Closure $fail): void
    {
        if (!$value) {
            return;
        }

        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (str_contains($part, '/')) {
                [$subnet, $bits] = array_pad(explode('/', $part, 2), 2, null);
                $isIpv4 = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
                $isIpv6 = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
                $maxBits = $isIpv4 ? 32 : ($isIpv6 ? 128 : null);

                if ($maxBits === null || !ctype_digit((string) $bits) || (int) $bits > $maxBits) {
                    $fail("\"{$part}\" nao e uma rede valida (formato esperado: IP/prefixo, ex: 192.168.1.0/24).");
                    return;
                }
            } elseif (filter_var($part, FILTER_VALIDATE_IP) === false) {
                $fail("\"{$part}\" nao e um endereco IP valido.");
                return;
            }
        }
    }
}
