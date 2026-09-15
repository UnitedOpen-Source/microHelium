@extends('layouts.app')

@section('title', 'Julgar Submissões')

@section('description', 'Revise os envios pendentes e consulte os julgamentos recentes.')

@section('content')
<a href="/judge/health" class="button-secondary mb-5">Saúde do julgamento</a>
<div class="space-y-6">

    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <h2 class="text-xl font-semibold text-foreground">Pendentes de Julgamento</h2>
            <p class="text-sm text-muted-foreground">{{ $contest?->name ?? 'Nenhum contest ativo' }}</p>
        </div>
        @include('partials.table-filter', ['tableId' => 'judge-runs-table', 'searchLabel' => 'registros'])
        <div class="overflow-x-auto">
            <table id="judge-runs-table" class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Run</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Time</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Site</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Problema</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Linguagem</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Julgar</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($pendingRuns as $run)
                    <tr class="hover:bg-muted/50 transition-colors {{ $run->isOverdue() ? 'bg-destructive-soft/40' : '' }}">
                        <td class="px-4 py-3 font-mono text-sm">
                            #{{ $run->run_number }}
                            @if($run->isOverdue())
                                <span class="ml-1 px-1.5 py-0.5 text-xs font-medium bg-destructive-soft text-destructive rounded" title="Aguardando julgamento ha mais tempo que o limite do site">Atrasado</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm">{{ $run->user->fullname ?? $run->user->username }}</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $run->site->name ?? '-' }}</td>
                        <td class="px-4 py-3 text-sm">{{ $run->problem->short_name }} - {{ $run->problem->name }}</td>
                        <td class="px-4 py-3 text-sm">{{ $run->language->name }}</td>
                        <td class="px-4 py-3">
                            <form action="{{ route('judge.runs.judge', $run) }}" method="POST" class="flex items-center gap-2">
                                @csrf
                                <select aria-label="Veredito da submissão {{ $run->run_number }}" name="answer_id" required class="text-sm px-2 py-1.5 bg-background border border-border rounded-lg">
                                    <option value="">Veredito...</option>
                                    @foreach($answers as $answer)
                                        <option value="{{ $answer->id }}">{{ $answer->name }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="px-3 py-1.5 text-xs font-medium bg-primary text-primary-foreground rounded hover:bg-primary-hover transition-colors">
                                    Confirmar
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-muted-foreground">Nenhuma submissao pendente</td>
                    </tr>
                    @endforelse
                <tr id="judge-runs-table-empty" hidden><td colspan="6" class="text-center text-muted-foreground">Nenhum resultado para estes filtros.</td></tr></tbody>
            </table>
        </div>
    </div>

    {{-- Issue #138: only rendered when this contest turned the gate on.
         A judged run that has not been verified is invisible to its team,
         so it belongs in a queue of outstanding work, not in the archive. --}}
    @if($contest?->verification_required)
    <div class="bg-card rounded-lg border border-warning/40 shadow-sm">
        <div class="p-6 border-b border-border">
            <h2 class="text-xl font-semibold text-foreground">Aguardando verificação</h2>
            <p class="text-sm text-muted-foreground">
                Este concurso exige verificação antes da publicação. Enquanto uma run estiver nesta lista,
                a equipe não vê o veredito e ele não conta no placar. Quem verifica não altera o veredito &mdash;
                se discordar, use o rejulgamento.
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Run</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Time</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Problema</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Veredito</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($awaitingVerification as $run)
                    <tr class="hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-3 font-mono text-sm">#{{ $run->run_number }}</td>
                        <td class="px-4 py-3 text-sm">{{ $run->user->fullname ?? $run->user->username }}</td>
                        <td class="px-4 py-3 text-sm">{{ $run->problem->short_name }} - {{ $run->problem->name }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 text-xs font-medium rounded {{ $run->answer?->is_accepted ? 'bg-success-soft text-success ' : 'bg-destructive-soft text-destructive ' }}">
                                {{ $run->answer->short_name ?? '-' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <form method="POST" action="{{ route('judge.runs.verify', $run) }}" class="flex flex-wrap items-center gap-2">
                                @csrf
                                <label class="sr-only" for="verify-comment-{{ $run->id }}">Observação da verificação da run {{ $run->run_number }}</label>
                                <input id="verify-comment-{{ $run->id }}" type="text" name="verify_comment" maxlength="2000"
                                       placeholder="Observação (opcional)"
                                       class="text-sm px-2 py-1.5 bg-background border border-border rounded-lg">
                                <button type="submit" class="px-3 py-1.5 text-sm font-medium rounded-lg bg-primary text-primary-foreground hover:bg-primary-hover transition-colors">
                                    Liberar veredito
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center text-muted-foreground">Nenhuma run aguardando verificação</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endif

    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <h2 class="text-xl font-semibold text-foreground">Julgadas Recentemente</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Run</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Time</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Problema</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Veredito</th>
                        @if($contest?->verification_required)
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Verificação</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($judgedRuns as $run)
                    <tr class="hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-3 font-mono text-sm">#{{ $run->run_number }}</td>
                        <td class="px-4 py-3 text-sm">{{ $run->user->fullname ?? $run->user->username }}</td>
                        <td class="px-4 py-3 text-sm">{{ $run->problem->short_name }} - {{ $run->problem->name }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 text-xs font-medium rounded {{ $run->answer?->is_accepted ? 'bg-success-soft text-success ' : 'bg-destructive-soft text-destructive ' }}">
                                {{ $run->answer->short_name ?? '-' }}
                            </span>
                        </td>
                        @if($contest?->verification_required)
                        <td class="px-4 py-3 text-sm">
                            @if($run->isVerified())
                                <form method="POST" action="{{ route('judge.runs.unverify', $run) }}" class="flex flex-wrap items-center gap-2">
                                    @csrf
                                    <span class="px-2 py-1 text-xs font-medium rounded bg-success-soft text-success">
                                        Liberado por {{ $run->verifier->fullname ?? $run->verifier->username ?? '—' }}
                                    </span>
                                    <button type="submit" class="px-2 py-1 text-xs font-medium rounded-lg border border-border hover:bg-muted transition-colors">
                                        Revogar
                                    </button>
                                </form>
                                @if($run->verify_comment)
                                    <p class="mt-1 text-xs text-muted-foreground">{{ $run->verify_comment }}</p>
                                @endif
                            @else
                                <span class="px-2 py-1 text-xs font-medium rounded bg-warning-soft text-warning">Oculto para a equipe</span>
                            @endif
                        </td>
                        @endif
                    </tr>
                    @empty
                    <tr>
                        <td colspan="{{ $contest?->verification_required ? 5 : 4 }}" class="px-4 py-12 text-center text-muted-foreground">Nenhuma submissao julgada ainda</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
