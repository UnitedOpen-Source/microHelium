<?php

namespace App\Services\Clics;

use App\Models\Organization;
use Helium\User;

/**
 * Issue #270 -- por qual instituição esta equipe compete.
 *
 * Substitui `OrganizationMembershipLookup`, que respondia esta pergunta
 * lendo `organization_memberships` -- a tabela que o #46 criou para
 * responder OUTRA: "quem pode editar as etiquetas e o dono dos problemas
 * desta organização". Permissão sobre acervo, com coluna `role` cujo padrão
 * é `editor`.
 *
 * O que a inferência produzia:
 *
 *   equipe que não é membro de banco nenhum        -> sem instituição
 *   usuário membro de duas organizações            -> a primeira por id
 *   quem edita o banco de uma e compete por outra  -> afiliação errada
 *
 * E a terceira é a pior, porque parece certa.
 *
 * Um ranking nacional por instituição não se sustenta sobre isso: se duas
 * edições discordarem sobre a qual universidade uma equipe pertence, o
 * agregado fica errado e ninguém consegue auditar por quê -- a resposta
 * dependia de qual linha de permissão de banco tem o menor id.
 *
 * A classe continua existindo, e isolada, pela razão que o #195 escreveu: a
 * lista de equipes e a lista de organizações precisam da MESMA resposta, e
 * se discordassem a Contest API sairia com integridade referencial quebrada
 * -- o único requisito duro da fase 1.
 */
class TeamAffiliation
{
    /** @var array<int, int|null> */
    private static array $cache = [];

    /** @var list<int>|null */
    private static ?array $existing = null;

    public static function for(User $team): ?int
    {
        $id = (int) $team->user_id;

        if (! array_key_exists($id, self::$cache)) {
            // Direto da coluna, e não de uma consulta: `organization_id` é
            // atributo do usuário, e quem chamou isto já tem o usuário
            // carregado. Uma consulta por equipe seriam centenas numa
            // regional, que é o mesmo motivo de o cache existir.
            $affiliation = $team->organization_id === null
                ? null
                : (int) $team->organization_id;

            self::$cache[$id] = $affiliation !== null && self::exists($affiliation)
                ? $affiliation
                : null;
        }

        return self::$cache[$id];
    }

    /**
     * A instituição citada existe?
     *
     * A coluna tem `foreignId(...)->nullOnDelete()`, e isso deveria bastar.
     * Não basta: medido nesta suíte, `PRAGMA foreign_keys` vem **0** na
     * conexão SQLite, ou seja as chaves estrangeiras estão declaradas e
     * **não são aplicadas**. Numa instalação SQLite, apagar uma organização
     * deixa `users.organization_id` apontando para linha que não existe.
     *
     * E o efeito não seria um campo estranho: `teams` citaria uma
     * `organization_id` que `/organizations` não lista, quebrando a
     * integridade referencial que o #195 estabeleceu como o ÚNICO requisito
     * duro da fase 1 -- e quebrando em silêncio, como uma linha faltando num
     * painel.
     *
     * Uma consulta por processo, memoizada como o resto: a lista de equipes
     * de uma regional pergunta isto uma vez por equipe.
     */
    private static function exists(int $organizationId): bool
    {
        self::$existing ??= Organization::query()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return in_array($organizationId, self::$existing, true);
    }

    public static function flush(): void
    {
        self::$cache = [];
        self::$existing = null;
    }
}
