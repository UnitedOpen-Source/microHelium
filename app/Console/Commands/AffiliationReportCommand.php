<?php

namespace App\Console\Commands;

use App\Models\Contest;
use App\Models\OrganizationMembership;
use Helium\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Issue #270 -- quem ainda não tem instituição, e quem a migração não pôde
 * decidir.
 *
 * A migração derivou `users.organization_id` apenas de quem tinha
 * EXATAMENTE uma organização em `organization_memberships`: ali a inferência
 * antiga e a afiliação coincidem, e não há o que escolher. Quem tinha duas
 * ou mais ficou nulo de propósito -- escolher pelo organizador é justamente
 * o defeito que a issue conserta.
 *
 * Este comando lista o que sobrou, porque um agregado nacional por
 * instituição não se sustenta sobre campo vazio nem sobre palpite. Mesmo
 * padrão de `IcpcReportBuilder::teamsMissingIcpcId()`: apontar o que falta
 * em vez de inventar.
 */
class AffiliationReportCommand extends Command
{
    protected $signature = 'affiliations:report
                            {--contest= : Id do contest a examinar (padrao: o ativo)}
                            {--json : Saida legivel por outra ferramenta}';

    protected $description = 'Lista equipes sem instituicao e casos que a migracao do #270 nao pôde decidir';

    public function handle(): int
    {
        $contest = $this->resolveContest();

        if (! $contest instanceof Contest) {
            $this->error('Nenhum contest ativo. Informe --contest=ID.');

            return self::FAILURE;
        }

        $teams = User::query()
            ->where('contest_id', $contest->id)
            ->where('user_type', User::TYPE_TEAM)
            ->orderBy('user_id')
            ->get();

        $sem = $teams->filter(fn (User $team) => $team->organization_id === null);
        $ambiguas = $this->ambiguous($sem);

        // Quem a migração não pôde decidir é subconjunto de quem está sem:
        // as duas listas se sobrepõem de propósito, e a segunda é a que tem
        // resposta pronta esperando escolha.
        $semPista = $sem->reject(fn (User $team) => $ambiguas->has((int) $team->user_id));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'contest' => ['id' => $contest->id, 'name' => $contest->name],
                'teams' => $teams->count(),
                'with_affiliation' => $teams->count() - $sem->count(),
                'without_affiliation' => $sem->count(),
                'ambiguous' => $ambiguas->map(fn (Collection $orgs, int $userId) => [
                    'user_id' => $userId,
                    'candidates' => $orgs->values()->all(),
                ])->values()->all(),
                'no_clue' => $semPista->map(fn (User $team) => [
                    'user_id' => (int) $team->user_id,
                    'name' => $team->fullname ?? $team->username,
                ])->values()->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $sem->isEmpty() ? self::SUCCESS : self::FAILURE;
        }

        $this->line("Afiliacao das equipes -- {$contest->name}");
        $this->newLine();
        $this->line('equipes                   : '.$teams->count());
        $this->line('com instituicao           : '.($teams->count() - $sem->count()));
        $this->line('sem instituicao           : '.$sem->count());
        $this->newLine();

        if ($sem->isEmpty()) {
            $this->info('Nada a resolver.');

            return self::SUCCESS;
        }

        if ($ambiguas->isNotEmpty()) {
            $this->warn('A migracao nao pôde decidir por estas: mais de uma organizacao no banco de problemas.');
            $this->newLine();

            $this->table(
                ['user_id', 'equipe', 'candidatas (governanca do banco)'],
                $ambiguas->map(function (Collection $orgs, int $userId) use ($teams) {
                    $team = $teams->firstWhere('user_id', $userId);

                    return [$userId, $team?->fullname ?? $team?->username ?? '?', $orgs->implode(', ')];
                })->values()->all()
            );

            $this->line('Escolher uma destas NAO e decisao do sistema: quem edita o acervo de uma');
            $this->line('instituicao pode competir por outra, e foi esse o defeito do #270.');
            $this->newLine();
        }

        if ($semPista->isNotEmpty()) {
            $this->warn('Sem pista nenhuma -- nunca tocaram o banco de problemas:');
            $this->newLine();

            $this->table(
                ['user_id', 'equipe'],
                $semPista->map(fn (User $team) => [
                    (int) $team->user_id,
                    $team->fullname ?? $team->username,
                ])->values()->all()
            );
        }

        // Falha de proposito: este comando existe para entrar num checklist
        // pre-prova, e um checklist que passa com afiliacao faltando nao
        // checa nada. Ver o manual do organizador.
        return self::FAILURE;
    }

    /**
     * Candidatas por usuário, para quem tem mais de uma.
     *
     * @param  Collection<int, User>  $teams
     * @return Collection<int, Collection<int, string>>
     */
    private function ambiguous(Collection $teams): Collection
    {
        if ($teams->isEmpty()) {
            return collect();
        }

        return OrganizationMembership::query()
            ->with('organization:id,name')
            ->whereIn('user_id', $teams->pluck('user_id'))
            ->orderBy('user_id')
            ->orderBy('organization_id')
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $rows) => $rows
                ->map(fn (OrganizationMembership $row) => $row->organization?->name ?? ('#'.$row->organization_id))
                ->unique()
                ->values())
            ->filter(fn (Collection $orgs) => $orgs->count() > 1)
            ->mapWithKeys(fn (Collection $orgs, $userId) => [(int) $userId => $orgs]);
    }

    private function resolveContest(): ?Contest
    {
        $id = $this->option('contest');

        if ($id !== null) {
            return Contest::find($id);
        }

        return Contest::query()->competition()->where('is_active', true)->first();
    }
}
