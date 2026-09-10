@extends('layouts.app')

@section('title', 'Meu Site')

@section('description', 'Acompanhe submissões, times e o andamento do seu site.')

@section('content')
<div class="space-y-6">
    {{-- layouts/app.blade.php already renders session('success')/session('error') globally --}}

    @if(!$site)
    <div class="bg-card rounded-lg border border-border shadow-sm p-6 text-center text-muted-foreground">
        Sua conta nao esta vinculada a nenhum site.
    </div>
    @else
    <div class="bg-card rounded-lg border border-border shadow-sm p-6">
        <h2 class="text-xl font-semibold text-foreground">{{ $site->name }}</h2>
        <p class="text-sm text-muted-foreground">{{ $contest?->name ?? 'Nenhuma competicao vinculada' }}</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-6 border-b border-border">
                <h3 class="font-semibold text-foreground">Submissões recentes</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Time</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Problema</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Veredito</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($recentRuns as $run)
                        <tr>
                            <td class="px-4 py-3 text-sm">{{ $run->user->fullname ?? $run->user->username }}</td>
                            <td class="px-4 py-3 text-sm">{{ $run->problem ? "{$run->problem->short_name} - {$run->problem->name}" : 'Problema removido' }}</td>
                            <td class="px-4 py-3">
                                @if($run->status !== 'judged')
                                    <span class="px-2 py-1 text-xs font-medium bg-muted text-foreground rounded">Aguardando</span>
                                @else
                                    <span class="px-2 py-1 text-xs font-medium rounded {{ $run->answer?->is_accepted ? 'bg-success-soft text-success' : 'bg-destructive-soft text-destructive' }}">{{ $run->answer->short_name ?? '-' }}</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="3" class="px-4 py-12 text-center text-muted-foreground">Nenhuma submissao neste site ainda</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-6 border-b border-border">
                <h3 class="font-semibold text-foreground">Times do site</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Nome</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Usuario</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($scoreboard as $team)
                        <tr>
                            <td class="px-4 py-3 text-sm">{{ $team->fullname }}</td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ $team->username }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="2" class="px-4 py-12 text-center text-muted-foreground">Nenhum time cadastrado neste site ainda</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif
</div>
@endsection
