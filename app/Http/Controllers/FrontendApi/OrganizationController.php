<?php

namespace App\Http\Controllers\FrontendApi;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProblemBank;
use App\Support\IdempotencyStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Issue #188 -- a fase que o #46 deixou explicitamente para depois.
 *
 * O #46 entregou a governanca do banco de problemas: um problema pertence a
 * uma organizacao, e quem e `editor` dela pode edita-lo. So que nao existia
 * NENHUM jeito de criar uma organizacao -- `Organization::create()` nao era
 * chamado em lugar nenhum de app/ ou routes/, e a spec registrava a
 * pendencia: "organizations e organization_memberships so podem ser
 * povoadas fora da UI (seed/tinker) ate essa fase existir".
 *
 * A consequencia pratica e que /backend/bank-governance abria com zero
 * organizacoes em qualquer instalacao real: o seletor de proprietario ficava
 * vazio, e metade do #46 -- "quem pode editar" -- era inalcancavel sem
 * `php artisan tinker`.
 *
 * Mesma superficie do resto: sessao + CSRF, fora do contrato auth:sanctum
 * de routes/api.php, com Idempotency-Key nas escritas.
 */
class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $memberships = OrganizationMembership::query()
            ->with('user:user_id,fullname,username')
            ->get()
            ->groupBy('organization_id');

        $problemCounts = ProblemBank::query()
            ->selectRaw('owning_org_id, COUNT(*) as total')
            ->whereNotNull('owning_org_id')
            ->groupBy('owning_org_id')
            ->pluck('total', 'owning_org_id');

        return response()->json([
            'data' => [
                'organizations' => Organization::orderBy('name')->get()->map(fn (Organization $org) => [
                    'id' => $org->id,
                    'name' => $org->name,
                    'archived' => $org->archived_at !== null,
                    'archived_at' => $org->archived_at?->toISOString(),
                    // O numero de problemas aparece porque e o que decide se
                    // arquivar e uma decisao inocente ou nao. Arquivar uma
                    // organizacao com 200 problemas tira ela do seletor sem
                    // tirar os problemas dela -- ver `update()`.
                    'problem_count' => (int) ($problemCounts[$org->id] ?? 0),
                    'members' => $memberships->get($org->id, collect())
                        ->map(fn (OrganizationMembership $membership) => [
                            'user_id' => $membership->user_id,
                            'name' => $membership->user?->fullname ?? $membership->user?->username,
                            'role' => $membership->role,
                        ])->values(),
                ])->values(),
                'roles' => [OrganizationMembership::ROLE_EDITOR],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return IdempotencyStore::handle($request, 'organizations.store', function () use ($request) {
            $validated = $request->validate([
                'name' => 'required|string|max:120|unique:organizations,name',
            ]);

            $organization = Organization::create(['name' => $validated['name']]);

            return response()->json(['data' => $this->present($organization)], 201);
        });
    }

    /**
     * Renomear, arquivar e desarquivar.
     *
     * ARQUIVAR NAO ORFANA NADA, e a issue pede que isso esteja escrito e nao
     * inferido:
     *
     *  - os problemas continuam apontando para a organizacao arquivada. Uma
     *    limpeza que zerasse `owning_org_id` transformaria "esta instituicao
     *    saiu" em "estes 200 problemas nao sao de ninguem", e o #46 trata
     *    problema sem dono como caso legado que so admin ve.
     *  - a organizacao some do SELETOR de proprietario: nao da mais para
     *    escolher ela para um problema novo. E o que arquivar significa.
     *  - os membros dela CONTINUAM podendo editar os problemas dela.
     *    Tirar isso deixaria problemas que ninguem alcanca -- o mesmo
     *    orfanato, por outro caminho. Arquivar e "nao receba mais", nao
     *    "fique intocavel".
     *
     * Desarquivar existe porque arquivar e um estado e nao uma exclusao: a
     * coluna e um timestamp anulavel, e uma instituicao que volta no ano
     * seguinte nao deveria precisar de uma organizacao nova com o mesmo
     * nome.
     */
    public function update(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:120|unique:organizations,name,'.$organization->id,
            'archived' => 'sometimes|boolean',
        ]);

        if (array_key_exists('name', $validated)) {
            $organization->name = $validated['name'];
        }

        if (array_key_exists('archived', $validated)) {
            $organization->archived_at = $validated['archived'] ? now() : null;
        }

        $organization->save();

        return response()->json(['data' => $this->present($organization->fresh())]);
    }

    public function addMember(Request $request, Organization $organization): JsonResponse
    {
        return IdempotencyStore::handle($request, 'organizations.members.add', function () use ($request, $organization) {
            $validated = $request->validate([
                'user_id' => 'required|integer|exists:users,user_id',
                'role' => 'sometimes|string|in:'.OrganizationMembership::ROLE_EDITOR,
            ]);

            // firstOrCreate e nao create: adicionar duas vezes e um clique
            // repetido, nao um erro, e uma segunda linha daria ao membro
            // dois vinculos que a remocao teria que apagar em duplicata.
            OrganizationMembership::firstOrCreate(
                ['organization_id' => $organization->id, 'user_id' => $validated['user_id']],
                ['role' => $validated['role'] ?? OrganizationMembership::ROLE_EDITOR],
            );

            return response()->json(['data' => $this->present($organization->fresh())], 201);
        });
    }

    public function removeMember(Request $request, Organization $organization, int $userId): JsonResponse
    {
        DB::transaction(function () use ($organization, $userId) {
            OrganizationMembership::where('organization_id', $organization->id)
                ->where('user_id', $userId)
                ->delete();
        });

        return response()->json(['data' => $this->present($organization->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'archived' => $organization->archived_at !== null,
            'archived_at' => $organization->archived_at?->toISOString(),
            'members' => OrganizationMembership::where('organization_id', $organization->id)
                ->with('user:user_id,fullname,username')
                ->get()
                ->map(fn (OrganizationMembership $membership) => [
                    'user_id' => $membership->user_id,
                    'name' => $membership->user?->fullname ?? $membership->user?->username,
                    'role' => $membership->role,
                ])->values(),
        ];
    }
}
