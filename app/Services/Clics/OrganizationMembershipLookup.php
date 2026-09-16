<?php

namespace App\Services\Clics;

use App\Models\OrganizationMembership;
use Helium\User;

/**
 * Issue #195 -- a organizacao de uma equipe.
 *
 * Nao existe `users.organization_id`: o vinculo mora em
 * OrganizationMembership (#46). Isolado num lugar so porque duas partes da
 * traducao precisam da mesma resposta -- a lista de equipes e a lista de
 * organizacoes -- e se discordassem a Contest API sairia com integridade
 * referencial quebrada, que e o unico requisito duro da fase 1.
 *
 * Memoizado por processo: a lista de equipes de uma regional pergunta isto
 * uma vez por equipe, e sao centenas.
 */
class OrganizationMembershipLookup
{
    /** @var array<int, int|null> */
    private static array $cache = [];

    public static function for(User $team): ?int
    {
        $id = (int) $team->user_id;

        if (! array_key_exists($id, self::$cache)) {
            self::$cache[$id] = OrganizationMembership::where('user_id', $id)
                ->orderBy('id')
                ->value('organization_id');
        }

        return self::$cache[$id] === null ? null : (int) self::$cache[$id];
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
