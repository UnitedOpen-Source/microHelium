@extends('layouts.app')

@section('title', 'Placar')

@section('content')
<div class="space-y-6">
    <!-- Main Scoreboard -->
    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <div class="border-b border-border px-6 py-4">
            <h3 class="text-lg font-semibold flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
                Placar da Competição
            </h3>
            <p class="text-sm text-muted-foreground">Classificação em tempo real dos times participantes</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-border bg-muted/50">
                        <th class="w-16 px-4 py-3 text-center text-xs font-medium text-muted-foreground uppercase tracking-wider">#</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Time</th>
                        <th class="w-24 px-4 py-3 text-center text-xs font-medium text-muted-foreground uppercase tracking-wider">Resolvidos</th>
                        <th class="w-24 px-4 py-3 text-center text-xs font-medium text-muted-foreground uppercase tracking-wider">Pontuação</th>
                        <th class="w-28 px-4 py-3 text-center text-xs font-medium text-muted-foreground uppercase tracking-wider">Penalidade</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Problemas</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($teams as $index => $team)
                    <tr class="hover:bg-muted/50 transition-colors {{ $index < 3 ? 'bg-success/5' : '' }}">
                        <td class="px-4 py-3 text-center">
                            @if($index == 0)
                                <span class="text-2xl">🥇</span>
                            @elseif($index == 1)
                                <span class="text-2xl">🥈</span>
                            @elseif($index == 2)
                                <span class="text-2xl">🥉</span>
                            @else
                                <span class="font-bold text-muted-foreground">{{ $index + 1 }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-semibold">{{ $team->teamName }}</div>
                            @if($team->email)
                                <div class="text-xs text-muted-foreground">{{ $team->email }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center rounded-md bg-primary/10 px-2.5 py-1 text-sm font-semibold text-primary ring-1 ring-inset ring-primary/20">
                                {{ $team->problems_solved ?? 0 }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="text-lg font-bold text-primary">{{ $team->score ?? 0 }}</span>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-muted-foreground">
                            {{ $team->penalty ?? 0 }} min
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                @for($i = 0; $i < 5; $i++)
                                    @php
                                        $status = $team->problem_status[$i] ?? 'pending';
                                    @endphp
                                    @if($status == 'accepted')
                                        <span class="inline-flex items-center justify-center w-7 h-7 rounded bg-success/10 text-xs font-medium text-success ring-1 ring-inset ring-success/20" title="Problema {{ chr(65+$i) }}: Aceito">
                                            {{ chr(65+$i) }}
                                        </span>
                                    @elseif($status == 'wrong')
                                        <span class="inline-flex items-center justify-center w-7 h-7 rounded bg-destructive/10 text-xs font-medium text-destructive ring-1 ring-inset ring-destructive/20" title="Problema {{ chr(65+$i) }}: Tentativas erradas">
                                            {{ chr(65+$i) }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center justify-center w-7 h-7 rounded bg-muted text-xs font-medium text-muted-foreground ring-1 ring-inset ring-border" title="Problema {{ chr(65+$i) }}: Não tentado">
                                            {{ chr(65+$i) }}
                                        </span>
                                    @endif
                                @endfor
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-muted-foreground/50 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                            <p class="text-muted-foreground">Nenhum time participando ainda</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Legend & Timer -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 rounded-xl border border-border bg-card overflow-hidden">
            <div class="border-b border-border px-6 py-4">
                <h3 class="text-lg font-semibold flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Legenda
                </h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-3">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded bg-success/10 text-xs font-medium text-success ring-1 ring-inset ring-success/20">A</span>
                            <span class="text-sm text-muted-foreground">Problema aceito</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded bg-destructive/10 text-xs font-medium text-destructive ring-1 ring-inset ring-destructive/20">A</span>
                            <span class="text-sm text-muted-foreground">Tentativas incorretas</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded bg-muted text-xs font-medium text-muted-foreground ring-1 ring-inset ring-border">A</span>
                            <span class="text-sm text-muted-foreground">Não tentado</span>
                        </div>
                    </div>
                    <div class="space-y-2 text-sm">
                        <p><strong class="text-foreground">Resolvidos:</strong> <span class="text-muted-foreground">Número de problemas aceitos</span></p>
                        <p><strong class="text-foreground">Pontuação:</strong> <span class="text-muted-foreground">Total de pontos acumulados</span></p>
                        <p><strong class="text-foreground">Penalidade:</strong> <span class="text-muted-foreground">Tempo total + penalidades por erros</span></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card overflow-hidden">
            <div class="border-b border-border px-6 py-4">
                <h3 class="text-lg font-semibold flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Tempo
                </h3>
            </div>
            <div class="p-6 text-center">
                <div class="text-4xl font-mono font-bold text-foreground mb-2" id="scoreboard-timer">
                    <contest-timer></contest-timer>
                </div>
                <p class="text-sm text-muted-foreground">Tempo restante</p>
            </div>
        </div>
    </div>
</div>
@endsection
