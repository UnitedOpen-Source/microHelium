<?php

namespace App\Http\Controllers\Api\Frontend;

use App\Http\Controllers\Controller;
use App\Models\AccountActivation;
use App\Models\Contest;
use App\Models\Site;
use App\Support\IdempotencyStore;
use App\Support\ProfilePrivacyPolicy;
use Helium\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
            $needle = mb_strtolower($search);
            $query->where(function ($sub) use ($needle) {
                $sub->whereRaw('LOWER(fullname) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(username) LIKE ?', ['%'.$needle.'%']);
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

        $contests = Contest::query()->with(['sites' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get()
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

    public function store(Request $request): JsonResponse
    {
        return IdempotencyStore::handle($request, self::ROUTE, function () use ($request) {
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
                // assigned to a new account. Same guard already used by
                // Backend\UserController::store() for site_id.
                'contest_id' => ['required', 'integer', Rule::exists('contests', 'id')->whereNull('deleted_at')],
                'site_id' => ['required', 'integer', Rule::exists('sites', 'id')->whereNull('deleted_at')],
            ]);

            $username = trim($validated['username']);
            $usernameTaken = User::query()
                ->whereRaw('LOWER(TRIM(username)) = ?', [mb_strtolower($username)])
                ->exists();

            if ($usernameTaken) {
                throw ValidationException::withMessages([
                    'username' => ['Este nome de usuario ja esta em uso.'],
                ]);
            }

            $site = Site::find($validated['site_id']);
            if (! $site || (int) $site->contest_id !== (int) $validated['contest_id']) {
                throw ValidationException::withMessages([
                    'site_id' => ['O local selecionado nao pertence ao concurso escolhido.'],
                ]);
            }

            try {
                [$user, $token] = DB::transaction(function () use ($validated, $username, $site) {
                    $user = User::create([
                        'fullname' => trim($validated['fullname']),
                        'username' => $username,
                        // Unusable password -- nobody can log in with this
                        // hash. The real credential is set via the
                        // activation flow below
                        // (AccountActivationController::store()).
                        'password' => Hash::make(Str::random(64)),
                        'user_type' => User::TYPE_TEAM,
                        'contest_id' => $site->contest_id,
                        'site_id' => $site->id,
                        // Disabled until activation completes -- "criar role
                        // team habilitada apenas conforme processo de
                        // ativação".
                        'is_enabled' => false,
                        'birthdate' => $validated['birthdate'] ?? null,
                        'managed_by' => auth()->id(),
                        'managed_at' => now(),
                        'profile_visibility' => 'private',
                    ]);

                    $token = Str::random(64);
                    AccountActivation::create([
                        'user_id' => $user->user_id,
                        // Only the hash is stored -- the raw token exists
                        // only in the URL returned below and in the
                        // recipient's link.
                        'token_hash' => hash('sha256', $token),
                        // 72h, documented in docs/specs/47-managed-accounts.md.
                        'expires_at' => now()->addHours(72),
                    ]);

                    return [$user, $token];
                });
            } catch (QueryException $e) {
                // The pre-check above is a plain SELECT, not a lock -- two
                // requests for the same username can both pass it before
                // either commits (e.g. a genuine concurrent duplicate
                // submission, not just this IdempotencyStore-covered
                // same-key retry). The DB's unique index on users.username
                // is the real guard in that race; surface it as the same
                // clean 422 the pre-check would have produced instead of
                // letting a raw QueryException turn into a 500.
                if (str_contains(strtolower($e->getMessage()), 'unique')) {
                    throw ValidationException::withMessages([
                        'username' => ['Este nome de usuario ja esta em uso.'],
                    ]);
                }

                throw $e;
            }

            return response()->json([
                'data' => [
                    'id' => $user->user_id,
                    'activation_url' => '/activate/'.$token,
                ],
            ], 201);
        });
    }
}
