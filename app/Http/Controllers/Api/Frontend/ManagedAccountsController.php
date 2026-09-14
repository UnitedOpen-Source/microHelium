<?php

namespace App\Http\Controllers\Api\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Contest;
use App\Models\Site;
use App\Services\ManagedAccountProvisioner;
use App\Services\UsernameTakenException;
use App\Support\IdempotencyStore;
use App\Support\ProfilePrivacyPolicy;
use Helium\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Issue #47 -- contas gerenciadas. Real backend for
 * resources/js/features/ManagedAccounts.vue. Routes are registered on the
 * web guard + CSRF (routes/frontend_api_managed_accounts.php), not the
 * auth:sanctum group in routes/api.php -- see docs/specs/README.md.
 *
 * Access is admin-only for this first delivery ("Acesso inicial admin
 * apenas"); the 'admin' route middleware (IsAdminMiddleware) enforces that.
 */
class ManagedAccountsController extends Controller
{
    private const ROUTE = 'POST /api/frontend/managed-accounts';

    public function index(Request $request): JsonResponse
    {
        $query = User::query()->whereNotNull('managed_by')->with(['contest:id,name', 'site:id,name']);

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $needle = self::escapeLike(mb_strtolower($search));
            $query->where(function ($sub) use ($needle) {
                $sub->whereRaw("LOWER(fullname) LIKE ? ESCAPE '!'", ['%'.$needle.'%'])
                    ->orWhereRaw("LOWER(username) LIKE ? ESCAPE '!'", ['%'.$needle.'%']);
            });
        }

        $paginator = $query->orderBy('user_id')->paginate(20)->withQueryString();

        $items = $paginator->getCollection()->map(function (User $user) {
            // privacyLocked()/externalLinkingAllowed()/reason() each
            // independently re-derive ageStatus() from birthdate -- calling
            // privacyLocked() once here and reusing it avoids recomputing
            // the same Carbon math up to 3x per row across a 20-row page.
            $locked = ProfilePrivacyPolicy::privacyLocked($user);

            return [
                'id' => $user->user_id,
                'fullname' => $user->fullname,
                'username' => $user->username,
                'contest_name' => $user->contest?->name,
                'site_name' => $user->site?->name,
                'privacy_locked' => $locked,
                'visibility' => $user->profile_visibility ?: 'private',
                'external_linking_allowed' => ! $locked,
                'privacy_reason' => ProfilePrivacyPolicy::reasonForLockedState($locked),
            ];
        })->values();

        // Known minor inefficiency: contests/sites are refetched on every
        // request (every page, every search refinement) even though this
        // data only changes when a contest/site is created or edited
        // elsewhere. Deliberately not cached across requests -- an admin
        // who just created a contest/site expects it to appear in this
        // same create-account form immediately (docs/specs/README.md:
        // "Reconsultar depois de ações confirmadas"), and this table is
        // small enough (one row per contest/site, not per managed account)
        // that a correctness-risking cache isn't worth it here.
        // Issue #43: accounts are enrolled in events, never in the
        // technical practice contest.
        $contests = Contest::query()->competition()->with(['sites' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get()
            ->map(fn (Contest $contest) => [
                'id' => $contest->id,
                'name' => $contest->name,
                'sites' => $contest->sites->map(fn (Site $site) => [
                    'id' => $site->id,
                    'name' => $site->name,
                ])->values(),
            ])->values();

        return response()->json([
            'data' => [
                // Real activation flow exists (AccountActivationController)
                // and is admin-only, so it is safe to report true here --
                // see the spec's "Backend precisa implementar a página/
                // endpoint de ativação antes de oferecer can_create=true".
                'capabilities' => ['can_create' => true],
                'contests' => $contests,
                'items' => $items,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                ],
            ],
        ]);
    }

    public function store(Request $request, ManagedAccountProvisioner $provisioner): JsonResponse
    {
        return IdempotencyStore::handle($request, self::ROUTE, function () use ($request, $provisioner) {
            // Only these five fields are ever read from the request --
            // any user_type/visibility/etc. sent by a manipulated client is
            // silently ignored, never applied (spec: "Ignorar/rejeitar
            // user_type/visibility externos").
            $validated = $request->validate([
                'fullname' => ['required', 'string', 'max:255'],
                'username' => ['required', 'string', 'max:80'],
                'birthdate' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
                // Contest/Site both use SoftDeletes -- plain "exists:table,id"
                // ignores deleted_at and would let a soft-deleted contest/site
                // (invisible in this same endpoint's `contests` list and
                // everywhere else that queries through Eloquent) still be
                // assigned to a new account.
                'contest_id' => ['required', 'integer', Rule::exists('contests', 'id')->whereNull('deleted_at')],
                // Scoped to contest_id and non-deleted in the validator
                // itself, matching the pattern already established by
                // Backend\SiteController::store()/update() for the exact
                // same "site belongs to this contest" check -- no separate
                // Site::find() + manual int comparison needed afterwards.
                'site_id' => [
                    'required',
                    'integer',
                    Rule::exists('sites', 'id')->where('contest_id', $request->input('contest_id'))->whereNull('deleted_at'),
                ],
            ]);

            try {
                // Account creation itself lives in
                // App\Services\ManagedAccountProvisioner, shared with the
                // bulk ICPC/BOCA importer (issue #141, `teams:import`) so
                // that a team created here and a team created from a file
                // are the same kind of account -- same unusable password,
                // same disabled-until-activation state, same 72h
                // single-use token, same privacy defaults. Everything that
                // used to be inline here moved there unchanged; the
                // username pre-check and the unique-violation race it
                // cannot close moved with it.
                [$user, $token] = $provisioner->create([
                    'fullname' => $validated['fullname'],
                    'username' => $validated['username'],
                    'contest_id' => $validated['contest_id'],
                    'site_id' => $validated['site_id'],
                    'birthdate' => $validated['birthdate'] ?? null,
                ], auth()->id());
            } catch (UsernameTakenException) {
                // Surfaced as the same clean 422 the caller would have got
                // from a pre-request check, rather than a raw 500.
                throw ValidationException::withMessages([
                    'username' => ['Este nome de usuario ja esta em uso.'],
                ]);
            }

            return response()->json([
                'data' => [
                    'id' => $user->user_id,
                    'activation_url' => ManagedAccountProvisioner::activationPath($token),
                ],
            ], 201);
        });
    }

    /**
     * Laravel doesn't escape LIKE metacharacters in a bound parameter --
     * without this, a literal `%` or `_` in an admin's search term (e.g.
     * "jo_n") is silently treated as a wildcard and matches unrelated rows
     * (e.g. "john"/"joan"). Callers must pair this with an explicit
     * "ESCAPE '!'" clause on the LIKE itself. '!' is used instead of the
     * more common backslash purely to avoid an extra layer of PHP/SQL
     * string-escaping for the backslash character itself.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
