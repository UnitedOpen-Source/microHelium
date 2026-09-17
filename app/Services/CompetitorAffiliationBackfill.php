<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #270 -- deriva `users.organization_id` do que a instalação já tem.
 *
 * Instalações que rodaram com a inferência precisam de caminho: sem isto, a
 * migração acrescentaria uma coluna vazia e toda equipe perderia a
 * instituição que a Contest API vinha relatando -- trocar afiliação errada
 * por afiliação nenhuma não é conserto.
 *
 * Deriva APENAS de quem tem exatamente uma organização em
 * `organization_memberships`. Ali a inferência antiga e a afiliação
 * coincidem, e não há o que escolher. Quem tem duas ou mais fica nulo **de
 * propósito**: escolher pelo organizador é justamente o defeito que a issue
 * conserta, e `php artisan affiliations:report` lista esses casos.
 *
 * Mesmo padrão de `IcpcReportBuilder::teamsMissingIcpcId()`: apontar o que
 * falta em vez de inventar.
 *
 * Vive aqui, e não dentro da migração, por dois motivos: uma derivação de
 * dados que não pode ser reexecutada nem testada é uma derivação em que
 * ninguém confia, e quem for auditar o resultado precisa poder rodar de novo
 * e comparar.
 */
class CompetitorAffiliationBackfill
{
    /**
     * @return array{derived: int, ambiguous: int, skipped_missing_organization: int}
     */
    public function run(): array
    {
        if (! Schema::hasTable('organization_memberships') || ! Schema::hasColumn('users', 'organization_id')) {
            return ['derived' => 0, 'ambiguous' => 0, 'skipped_missing_organization' => 0];
        }

        $porUsuario = DB::table('organization_memberships')
            ->select('user_id', DB::raw('MIN(organization_id) as organization_id'), DB::raw('COUNT(DISTINCT organization_id) as quantas'))
            ->groupBy('user_id')
            ->get();

        // Só organizações que ainda existem. A coluna tem chave estrangeira
        // e, onde ela é aplicada, uma linha de governança órfã derrubaria a
        // migração inteira -- e onde NÃO é aplicada (medido: `PRAGMA
        // foreign_keys` vem 0 na conexão SQLite desta suíte) gravaria um id
        // pendurado, que é pior.
        $existentes = DB::table('organizations')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $derivadas = 0;
        $ambiguas = 0;
        $semOrganizacao = 0;

        foreach ($porUsuario as $linha) {
            if ((int) $linha->quantas > 1) {
                $ambiguas++;

                continue;
            }

            if (! in_array((int) $linha->organization_id, $existentes, true)) {
                $semOrganizacao++;

                continue;
            }

            // Não sobrescreve escolha já feita: rodar de novo depois de o
            // organizador ter resolvido um caso ambíguo à mão não pode
            // desfazer o trabalho dele.
            $derivadas += DB::table('users')
                ->where('user_id', $linha->user_id)
                ->whereNull('organization_id')
                ->update(['organization_id' => $linha->organization_id]);
        }

        return [
            'derived' => $derivadas,
            'ambiguous' => $ambiguas,
            'skipped_missing_organization' => $semOrganizacao,
        ];
    }
}
