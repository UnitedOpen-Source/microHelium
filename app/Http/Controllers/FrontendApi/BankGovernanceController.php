<?php

namespace App\Http\Controllers\FrontendApi;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProblemBank;
use App\Models\ProblemBankOwnershipTransfer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Issue #46 -- real backend for /backend/bank-governance
 * (docs/specs/46-bank-ownership.md; resources/js/features/BankGovernance.vue).
 *
 * Registered on the web session guard + CSRF, not routes/api.php's
 * auth:sanctum group -- see routes/frontend_api_bank_governance.php.
 *
 * `practice_status`/`can_publish` are deliberately always
 * "unpublished"/false: issue #43 (Treino Livre), which owns actual
 * publication, is not implemented yet. This is not a stub -- it is the
 * correct, honest answer given nothing can be published today.
 */
class BankGovernanceController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizations = $this->visibleOrganizations($user);
        $organizationIds = $organizations->pluck('id');

        $query = ProblemBank::query()->with('organization')->orderBy('name')->orderBy('id');

        if (! $user->isAdmin()) {
            $query->whereIn('owning_org_id', $organizationIds->all() ?: [-1]);
        }

        $rawSearch = $request->query('q');
        $search = is_scalar($rawSearch) ? trim((string) $rawSearch) : '';
        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', '%'.$search.'%')
                    ->orWhere('tags', 'like', '%'.$search.'%');
            });
        }

        $organizationFilter = $request->query('organization_id');
        if (! is_scalar($organizationFilter)) {
            // Malformed input (e.g. organization_id[]=1 arriving as an
            // array) is treated as "no filter" rather than crashing the
            // query builder on a non-scalar bind value.
            $organizationFilter = null;
        }
        if ($organizationFilter === 'unassigned') {
            $query->whereNull('owning_org_id');
            if (! $user->isAdmin()) {
                // Legacy items are admin-only; an editor asking for
                // "unassigned" must see zero items, not an error -- the
                // filter combines with scope without leaking anything.
                $query->whereRaw('1 = 0');
            }
        } elseif ($organizationFilter !== null && $organizationFilter !== '') {
            $query->where('owning_org_id', $organizationFilter);
            if (! $user->isAdmin() && ! $organizationIds->contains((int) $organizationFilter)) {
                $query->whereRaw('1 = 0');
            }
        }

        $paginated = $query->paginate(self::PER_PAGE)->withQueryString();

        return response()->json([
            'data' => [
                'organizations' => $organizations->map(fn (Organization $org) => [
                    'id' => $org->id,
                    'name' => $org->name,
                ])->values(),
                'items' => collect($paginated->items())
                    ->map(fn (ProblemBank $bank) => $this->present($bank, $user, $organizationIds))
                    ->values(),
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'total' => $paginated->total(),
                ],
            ],
        ]);
    }

    public function update(Request $request, ProblemBank $bank): JsonResponse
    {
        $user = $request->user();

        return DB::transaction(function () use ($request, $bank, $user) {
            // Lock + re-check membership/version inside the same
            // transaction as the write ("Verificar membership e versão
            // dentro da mesma transação da gravação"). A membership
            // revoked between the GET and this PATCH is re-read here, not
            // trusted from the client.
            $locked = ProblemBank::whereKey($bank->getKey())->lockForUpdate()->firstOrFail();

            if (! $user->can('update', $locked)) {
                abort(403, 'Você não tem permissão para editar este problema.');
            }

            $submittedVersion = $request->input('version');
            if (! is_string($submittedVersion) && ! is_numeric($submittedVersion)) {
                $this->fail('version', 'Versão ausente ou inválida. Atualize a página.');
            }
            if ((string) $submittedVersion !== (string) $locked->version) {
                // Stale write: reject without applying any part of the
                // update, and don't echo the current version back.
                abort(409, 'Os dados foram alterados por outra pessoa. Atualize a página antes de salvar.');
            }

            $requestedOwner = $this->normalizeOwner($request->input('owning_org_id'));
            $ownerChanged = $requestedOwner !== $locked->owning_org_id;

            if ($ownerChanged && ! $user->can('transfer', $locked)) {
                // 403 even when the editor *could* edit tags -- transfer is
                // a separate capability.
                abort(403, 'Você não tem permissão para transferir este problema.');
            }

            if ($requestedOwner !== null && ! Organization::whereKey($requestedOwner)->exists()) {
                $this->fail('owning_org_id', 'Organização inexistente.');
            }

            $tags = $this->normalizeTags($request->input('tags'));

            $previousOwner = $locked->owning_org_id;
            $locked->owning_org_id = $requestedOwner;
            $locked->tags = $tags;
            $locked->version = $locked->version + 1;
            $locked->save();

            if ($ownerChanged) {
                ProblemBankOwnershipTransfer::create([
                    'problem_bank_id' => $locked->id,
                    'from_organization_id' => $previousOwner,
                    'to_organization_id' => $requestedOwner,
                    'actor_user_id' => $user->getKey(),
                ]);
            }

            return response()->json([
                'data' => [
                    'id' => $locked->id,
                    'version' => (string) $locked->version,
                ],
            ]);
        });
    }

    /**
     * @return Collection<int, Organization>
     */
    private function visibleOrganizations($user): Collection
    {
        if ($user->isAdmin()) {
            return Organization::orderBy('name')->get();
        }

        return Organization::whereHas('memberships', function ($membership) use ($user) {
            $membership->where('user_id', $user->getKey())
                ->where('role', OrganizationMembership::ROLE_EDITOR);
        })->orderBy('name')->get();
    }

    private function present(ProblemBank $bank, $user, SupportCollection $editableOrgIds): array
    {
        $canTransfer = $user->isAdmin();
        $canEdit = $canTransfer
            || ($bank->owning_org_id !== null && $editableOrgIds->contains($bank->owning_org_id));

        return [
            'id' => $bank->id,
            'name' => $bank->name,
            'owning_org_id' => $bank->owning_org_id,
            'organization_name' => $bank->organization?->name,
            'tags' => array_values($bank->tags ?? []),
            'version' => (string) $bank->version,
            // #43 (Treino Livre) owns real publication; nothing is
            // publishable yet, so this is always false/"unpublished".
            'practice_status' => 'unpublished',
            'capabilities' => [
                'can_edit' => $canEdit,
                'can_transfer' => $canTransfer,
                'can_publish' => false,
            ],
        ];
    }

    private function normalizeOwner(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_numeric($raw)) {
            $this->fail('owning_org_id', 'Organização inválida.');
        }

        return (int) $raw;
    }

    /**
     * Tag validation is entirely server-side (docs/specs/46-bank-ownership.md):
     * at most 20 tags, at most 40 chars each, at most 500 chars of combined
     * raw input, trimmed/deduplicated (case-insensitively; the first-seen
     * casing is kept for display). Every failure is reported under the
     * `tags` key -- never `tags.3` -- so the existing FieldError.vue/
     * useFeature.js (which look up `errors[name]` and focus that exact
     * form field) can find it.
     */
    private function normalizeTags(mixed $rawTags): array
    {
        if (! is_array($rawTags)) {
            $this->fail('tags', 'Etiquetas inválidas.');
        }

        if (count($rawTags) > 20) {
            $this->fail('tags', 'No máximo 20 etiquetas são permitidas.');
        }

        // Laravel's ConvertEmptyStringsToNull middleware turns any blank
        // tag the client sent ("") into null before it reaches here --
        // that's a blank entry to skip, not an invalid type.
        $totalLength = 0;
        foreach ($rawTags as $tag) {
            if ($tag === null) {
                continue;
            }
            if (! is_string($tag)) {
                $this->fail('tags', 'Etiquetas inválidas.');
            }
            $totalLength += mb_strlen($tag);
        }
        if ($totalLength > 500) {
            $this->fail('tags', 'O texto das etiquetas excede o tamanho máximo permitido.');
        }

        $seen = [];
        $normalized = [];
        foreach ($rawTags as $tag) {
            if ($tag === null) {
                continue;
            }
            $trimmed = trim(preg_replace('/\s+/u', ' ', $tag));
            if ($trimmed === '') {
                continue;
            }
            if (mb_strlen($trimmed) > 40) {
                $this->fail('tags', 'Cada etiqueta deve ter no máximo 40 caracteres.');
            }
            $key = mb_strtolower($trimmed);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = $trimmed;
        }

        return $normalized;
    }

    private function fail(string $field, string $message): never
    {
        $validator = Validator::make([], []);
        $validator->errors()->add($field, $message);
        throw new ValidationException($validator);
    }
}
