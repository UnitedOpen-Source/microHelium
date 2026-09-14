@extends('layouts.app')

@section('title', 'S.O.S.')

@section('description', 'Chamados das equipes da sua sede.')

@section('content')
<div class="space-y-6">

    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <div class="flex items-center gap-3">
                <h2 class="text-xl font-semibold text-foreground">S.O.S.</h2>
                @if($openCount > 0)
                    <span class="px-2 py-1 text-xs font-semibold rounded bg-destructive-soft text-destructive">
                        {{ $openCount }} {{ $openCount === 1 ? 'chamado aberto' : 'chamados abertos' }}
                    </span>
                @endif
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ $contest?->name ?? 'Nenhum contest ativo' }} &mdash; equipes da sua sede pedindo alguém na mesa
                (máquina, energia, saúde). Dúvida sobre enunciado não aparece aqui: aquilo é clarificação e vai para a banca.
            </p>
        </div>

        @if(session('success'))
            <div class="mx-6 mt-6 p-4 rounded-lg bg-success-soft text-success text-sm" role="status">{{ session('success') }}</div>
        @endif

        @if(session('error'))
            <div class="mx-6 mt-6 p-4 rounded-lg bg-destructive-soft text-destructive text-sm" role="alert">{{ session('error') }}</div>
        @endif

        @include('partials.table-filter', ['tableId' => 'staff-sos-table', 'searchLabel' => 'chamados'])
        <div class="overflow-x-auto">
            <table id="staff-sos-table" class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">#</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Time</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Sede</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Observacao</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Atendido por</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Acao</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($calls as $call)
                    <tr class="hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-3 font-mono text-sm font-semibold text-foreground">#{{ $call->id }}</td>
                        <td class="px-4 py-3 text-sm">{{ $call->user->fullname ?? $call->user->username ?? '-' }}</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $call->site->name ?? '-' }}</td>
                        <td class="px-4 py-3 text-sm max-w-xs break-words">
                            {{-- Team-authored free text. Blade escapes it; it
                                 is never rendered raw and never reaches the
                                 audit log (issue #139). --}}
                            {{ $call->note ?: '-' }}
                        </td>
                        <td class="px-4 py-3">
                            @if($call->isResolved())
                                <span class="px-2 py-1 text-xs font-medium bg-success-soft text-success rounded">Resolvido</span>
                            @elseif($call->isAcknowledged())
                                <span class="px-2 py-1 text-xs font-medium bg-warning-soft text-warning rounded">Em atendimento</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium bg-destructive-soft text-destructive rounded">Aberto</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">
                            {{ $call->resolvedBy->fullname ?? $call->resolvedBy->username
                                ?? $call->acknowledgedBy->fullname ?? $call->acknowledgedBy->username ?? '-' }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                @if($call->isOpen())
                                <form action="{{ route('staff.sos.acknowledge', $call) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="px-3 py-1.5 text-xs font-medium bg-muted text-foreground rounded hover:bg-accent transition-colors">
                                        Estou indo<span class="sr-only"> atender o chamado #{{ $call->id }}</span>
                                    </button>
                                </form>
                                @endif
                                @unless($call->isResolved())
                                <form action="{{ route('staff.sos.resolve', $call) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="px-3 py-1.5 text-xs font-medium bg-primary text-primary-foreground rounded hover:bg-primary-hover transition-colors">
                                        Resolvido<span class="sr-only"> — encerrar o chamado #{{ $call->id }}</span>
                                    </button>
                                </form>
                                @else
                                <span class="text-muted-foreground text-sm">-</span>
                                @endunless
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">Nenhum chamado por enquanto</td>
                    </tr>
                    @endforelse
                <tr id="staff-sos-table-empty" hidden><td colspan="7" class="text-center text-muted-foreground">Nenhum resultado para estes filtros.</td></tr></tbody>
            </table>
        </div>
    </div>
</div>
@endsection
