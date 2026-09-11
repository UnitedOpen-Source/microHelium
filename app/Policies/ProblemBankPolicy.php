<?php

namespace App\Policies;

use App\Models\OrganizationMembership;
use App\Models\ProblemBank;
use Helium\User;

/**
 * Issue #46 -- authorization for who may mutate a ProblemBank row.
 *
 * This is the policy the spec calls out as needing to be applied not only
 * to the new /api/frontend/bank-governance endpoints but also to every
 * pre-existing action that mutates ProblemBank (toggle, destroy, the BOCA
 * import routes): "Reaplicar policy aos endpoints antigos ... caso
 * contrário a nova tela não resolve a vulnerabilidade de escopo."
 *
 * Rules (docs/specs/46-bank-ownership.md):
 * - Admin always bypasses (global scope preserved).
 * - A legacy item (owning_org_id === null) is admin-only.
 * - An "editor" member of the owning organization may update tags on
 *   their own organization's items, but transferring/unassigning
 *   ownership is a separate, admin-only capability ("proposta inicial:
 *   só admin transfere/desatribui").
 * - Deleting/toggling a bank item is also admin-only for now: the spec
 *   never grants that capability to org editors, only "editar" (tags).
 */
class ProblemBankPolicy
{
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, ProblemBank $bank): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($bank->owning_org_id === null) {
            return false;
        }

        return OrganizationMembership::query()
            ->where('organization_id', $bank->owning_org_id)
            ->where('user_id', $user->getKey())
            ->where('role', OrganizationMembership::ROLE_EDITOR)
            ->exists();
    }

    public function transfer(User $user, ProblemBank $bank): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, ProblemBank $bank): bool
    {
        return $user->isAdmin();
    }
}
