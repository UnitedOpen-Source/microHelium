@extends('layouts.app')

@section('title', 'Registro da competição')

@section('description', 'Auditoria do que aconteceu durante a competição.')

@section('content')
<div class="space-y-6">
    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <h2 class="text-xl font-semibold text-foreground">Registro da competição</h2>
            <p class="text-sm text-muted-foreground mt-1">
                {{-- Issue #88: this is the screen you reach for during a dispute. --}}
                Submissões, julgamentos, rejulgamentos e respostas a esclarecimentos, em ordem do mais recente.
            </p>
        </div>

        <form method="GET" class="p-6 border-b border-border grid gap-4 md:grid-cols-5">
            @if($isAdmin)
            <div>
                <label for="contest_id" class="block text-sm font-medium text-foreground mb-1">Competição</label>
                <select id="contest_id" name="contest_id" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground">
                    @foreach($contests as $option)
                        <option value="{{ $option->id }}" @selected($contest && $contest->id === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif

            <div>
                <label for="type" class="block text-sm font-medium text-foreground mb-1">Nível</label>
                <select id="type" name="type" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground">
                    <option value="">Todos</option>
                    @foreach(['error' => 'Erro', 'warning' => 'Aviso', 'info' => 'Informação', 'debug' => 'Depuração'] as $value => $label)
                        <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="from" class="block text-sm font-medium text-foreground mb-1">De</label>
                <input id="from" type="date" name="from" value="{{ $filters['from'] }}" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground">
            </div>

            <div>
                <label for="to" class="block text-sm font-medium text-foreground mb-1">Até</label>
                <input id="to" type="date" name="to" value="{{ $filters['to'] }}" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground">
            </div>

            <div>
                <label for="q" class="block text-sm font-medium text-foreground mb-1">Buscar</label>
                <div class="flex gap-2">
                    <input id="q" type="search" name="q" value="{{ $filters['q'] }}" maxlength="120" placeholder="Texto da mensagem" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground">
                    <button type="submit" class="px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary-hover transition-colors">Filtrar</button>
                </div>
            </div>
        </form>

        @if(!$contest)
            <div class="p-6">
                <p class="text-sm text-muted-foreground">Nenhuma competição disponível para consultar.</p>
            </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Quando</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Nível</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Mensagem</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Conta</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Sede</th>
                        @if($isAdmin)
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">IP</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($logs as $log)
                    <tr class="hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-3 text-sm font-mono whitespace-nowrap">{{ $log->created_at?->format('d/m H:i:s') }}</td>
                        <td class="px-4 py-3">
                            @php($tone = ['error' => 'bg-destructive-soft text-destructive', 'warning' => 'bg-warning-soft text-warning'][$log->type] ?? 'bg-muted text-muted-foreground')
                            <span class="px-2 py-1 text-xs font-medium rounded {{ $tone }}">{{ $log->type }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm">{{ $log->message }}</td>
                        <td class="px-4 py-3 text-sm">{{ $log->user?->fullname ?? $log->user?->username ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm">{{ $log->site?->name ?? '—' }}</td>
                        @if($isAdmin)
                        {{-- Issue #88: an IP is personal data. A judge or staff
                             member auditing a verdict has no need for it. --}}
                        <td class="px-4 py-3 text-sm font-mono">{{ $log->ip_address ?? '—' }}</td>
                        @endif
                    </tr>
                    @empty
                    <tr>
                        <td colspan="{{ $isAdmin ? 6 : 5 }}" class="px-4 py-8 text-center text-sm text-muted-foreground">
                            Nenhum registro para estes filtros.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-6 border-t border-border">
            {{ $logs->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
