@extends('layouts.app')
@section('title', 'Operações da competição')
@section('description', 'Confira o estado, resolva as pendências e finalize a competição com segurança.')
@section('content')
<div class="feature-stack">
    <nav aria-label="Navegação da competição" class="feature-actions">
        <a href="{{ route('backend.configurations') }}" class="button-secondary">Todas as competições</a>
        <a href="{{ route('backend.contest.edit', $contest->id) }}" class="button-secondary">Editar regras e agenda</a>
    </nav>
    <section class="surface feature-panel">
        <div class="feature-heading"><div><p class="eyebrow">COMPETIÇÃO #{{ $contest->id }}</p><h2>{{ $contest->name }}</h2></div>
            <span class="feature-badge">{{ $contest->isFinalized() ? 'Finalizada' : ($contest->isRunning() ? 'Em andamento' : ($contest->start_time?->isFuture() ? 'Agendada' : 'Fora do período de prova')) }}</span>
        </div>
        <dl class="feature-grid mt-5">
            <div><dt class="feature-help">Início</dt><dd>{{ $contest->start_time?->format('d/m/Y H:i T') ?? 'Não definido' }}</dd></div>
            <div><dt class="feature-help">Término previsto</dt><dd>{{ $contest->end_time?->format('d/m/Y H:i T') ?? 'Não definido' }}</dd></div>
            <div><dt class="feature-help">Placar</dt><dd>{{ $contest->isFrozen() ? 'Congelado — resultados ainda ocultos' : ($contest->isUnfrozen() ? 'Revelado' : 'Sem congelamento neste momento') }}</dd></div>
        </dl>
        <p class="feature-help mt-4">Encerrar o tempo de prova e finalizar são etapas diferentes. A finalização declara que não há pendências que possam alterar o resultado.</p>
    </section>
    <div class="feature-grid">
        <section class="surface feature-panel">
            <div class="feature-heading"><h2>1. Conferir pendências</h2><a href="{{ route('backend.contest.operations', $contest) }}" class="button-secondary">Atualizar verificação</a></div>
            @if($contest->isFinalized())
                <p class="feature-message feature-success">Finalizada em {{ $contest->finalized_at->format('d/m/Y H:i T') }}.</p>
            @elseif($blockers)
                <p class="feature-help">Resolva os impedimentos abaixo e atualize esta página.</p>
                <ul class="space-y-3 mt-4">
                    @foreach($blockers as $blocker)
                        <li class="feature-message"><strong>{{ $blocker['message'] }}</strong>
                            @if(in_array($blocker['code'], ['unjudged_submissions', 'judging_errors']))
                                <a class="feature-link block mt-2" href="{{ route('judge.health') }}">Consultar saúde do julgamento</a>
                            @elseif($blocker['code'] === 'unanswered_clarifications')
                                <a class="feature-link block mt-2" href="/backend/clarifications">Consultar esclarecimentos</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="feature-message feature-success">Nenhum impedimento encontrado. Confira o placar antes de confirmar.</p>
            @endif
        </section>
        <section class="surface feature-panel space-y-4">
            <h2>2. Revelar e finalizar</h2>
            @if($contest->isFrozen())
                <p class="feature-help">Revelar publica os resultados ocultos. Essa ação não pode ser desfeita.</p>
                @if($contest->isRunning())
                    <p class="feature-message">Aguarde o término da competição para revelar o placar.</p>
                @else
                    <form method="POST" action="{{ route('backend.contest.unfreeze', $contest) }}" data-confirm="Revelar o placar de {{ $contest->name }}? Os resultados ocultos ficarão públicos e não poderão ser ocultados novamente.">
                        @csrf
                        <button class="button-danger" type="submit">Revelar placar</button>
                    </form>
                @endif
            @endif
            @if(!$contest->isFinalized())
                <p class="feature-help">A confirmação verifica as pendências novamente no servidor e registra o responsável pela finalização.</p>
                <form method="POST" action="{{ route('backend.contest.finalize', $contest) }}" data-confirm="Finalizar {{ $contest->name }} e disponibilizar sua premiação? Confirme somente após revisar os resultados.">
                    @csrf
                    <button class="button-primary" type="submit" @disabled(count($blockers) > 0) @if($blockers) aria-describedby="finalize-help" @endif>Finalizar competição</button>
                </form>
                @if($blockers)<p id="finalize-help" class="feature-help">A finalização estará disponível quando todos os impedimentos forem resolvidos.</p>@endif
            @else
                <p class="feature-help">A competição já está finalizada. Consulte a premiação abaixo.</p>
            @endif
        </section>
    </div>
    <section class="surface feature-panel">
        <h2>3. Premiação</h2>
        @if(!$contest->isFinalized())
            <p class="feature-help mt-3">Disponível após a finalização. Nenhum resultado oculto é antecipado nesta tela.</p>
        @elseif(!$prizes)
            <p class="feature-help mt-3">Não há premiações calculadas para esta competição.</p>
        @else
            <div class="overflow-x-auto mt-4"><table class="w-full text-sm"><caption class="sr-only">Premiação de {{ $contest->name }}</caption><thead><tr><th scope="col" class="text-left p-3">Prêmio</th><th scope="col" class="text-left p-3">Equipes</th></tr></thead><tbody class="divide-y divide-border">
                @foreach($prizes as $prize)<tr><th scope="row" class="text-left p-3 font-medium">{{ $prize['citation'] }}</th><td class="p-3">{{ collect($prize['team_ids'])->map(fn ($id) => $teams[$id] ?? "Equipe #{$id}")->join(', ') }}</td></tr>@endforeach
            </tbody></table></div>
        @endif
    </section>
</div>
@endsection
