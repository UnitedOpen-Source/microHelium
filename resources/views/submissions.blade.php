@extends('layouts.app')

@section('title', 'Submissões')

@section('content')
<div class="space-y-6">
    <!-- Submissions Table -->
    <div class="rounded-xl border border-border bg-card overflow-hidden">
        <div class="border-b border-border px-6 py-4">
            <h3 class="text-lg font-semibold flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                </svg>
                Minhas Submissões
            </h3>
            <p class="text-sm text-muted-foreground">Histórico de todas as suas submissões na competição</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-border bg-muted/50">
                        <th class="w-16 px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">#</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Problema</th>
                        <th class="w-24 px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Linguagem</th>
                        <th class="w-32 px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Resultado</th>
                        <th class="w-20 px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Tempo</th>
                        <th class="w-24 px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Memória</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Data/Hora</th>
                        <th class="w-20 px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($submissions ?? [] as $submission)
                    <tr class="hover:bg-muted/50 transition-colors {{ $submission->result == 'AC' ? 'bg-success/5' : ($submission->result == 'WA' ? 'bg-destructive/5' : '') }}">
                        <td class="px-4 py-3 text-sm font-semibold">{{ $submission->id }}</td>
                        <td class="px-4 py-3 text-sm">
                            <span class="inline-flex items-center rounded-md bg-primary/10 px-2 py-1 text-xs font-medium text-primary ring-1 ring-inset ring-primary/20 mr-2">
                                {{ $submission->problem_letter ?? 'A' }}
                            </span>
                            {{ $submission->problem_name ?? '' }}
                        </td>
                        <td class="px-4 py-3 text-sm">
                            @php
                                $langStyles = [
                                    'c' => 'bg-sky-500/10 text-sky-600 ring-sky-500/20',
                                    'cpp' => 'bg-blue-500/10 text-blue-600 ring-blue-500/20',
                                    'java' => 'bg-orange-500/10 text-orange-600 ring-orange-500/20',
                                    'python' => 'bg-green-500/10 text-green-600 ring-green-500/20',
                                ];
                                $lang = strtolower($submission->language ?? 'c');
                            @endphp
                            <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $langStyles[$lang] ?? 'bg-muted text-muted-foreground ring-border' }}">
                                {{ strtoupper($submission->language ?? 'C') }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            @switch($submission->result ?? 'pending')
                                @case('AC')
                                    <span class="inline-flex items-center gap-1 rounded-md bg-success/10 px-2 py-1 text-xs font-medium text-success ring-1 ring-inset ring-success/20">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        Aceito
                                    </span>
                                    @break
                                @case('WA')
                                    <span class="inline-flex items-center gap-1 rounded-md bg-destructive/10 px-2 py-1 text-xs font-medium text-destructive ring-1 ring-inset ring-destructive/20">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                        Resposta Errada
                                    </span>
                                    @break
                                @case('TLE')
                                    <span class="inline-flex items-center gap-1 rounded-md bg-warning/10 px-2 py-1 text-xs font-medium text-warning ring-1 ring-inset ring-warning/20">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        Tempo Excedido
                                    </span>
                                    @break
                                @case('CE')
                                    <span class="inline-flex items-center gap-1 rounded-md bg-primary/10 px-2 py-1 text-xs font-medium text-primary ring-1 ring-inset ring-primary/20">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                        </svg>
                                        Erro Compilação
                                    </span>
                                    @break
                                @case('RTE')
                                    <span class="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-1 text-xs font-medium text-muted-foreground ring-1 ring-inset ring-border">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        Erro Execução
                                    </span>
                                    @break
                                @case('pending')
                                    <span class="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-1 text-xs font-medium text-muted-foreground ring-1 ring-inset ring-border animate-pulse">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        Julgando...
                                    </span>
                                    @break
                                @default
                                    <span class="inline-flex items-center rounded-md bg-muted px-2 py-1 text-xs font-medium text-muted-foreground ring-1 ring-inset ring-border">
                                        {{ $submission->result }}
                                    </span>
                            @endswitch
                        </td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $submission->time ?? '-' }}s</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $submission->memory ?? '-' }} KB</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $submission->created_at ?? '-' }}</td>
                        <td class="px-4 py-3 text-sm">
                            <a href="/submission/{{ $submission->id }}" class="inline-flex items-center gap-1 rounded-md bg-primary/10 px-2 py-1 text-xs font-medium text-primary hover:bg-primary/20 transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                                Ver
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="px-4 py-12 text-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-muted-foreground/50 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                            </svg>
                            <p class="text-muted-foreground mb-4">Você ainda não fez nenhuma submissão</p>
                            <a href="/exercises" class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                Ver Problemas
                            </a>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Legend & Stats -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 rounded-xl border border-border bg-card overflow-hidden">
            <div class="border-b border-border px-6 py-4">
                <h3 class="text-lg font-semibold flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Legenda dos Vereditos
                </h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="space-y-2">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center rounded-md bg-success/10 px-2 py-1 text-xs font-medium text-success ring-1 ring-inset ring-success/20">AC</span>
                            <span class="text-sm"><strong>Aceito</strong> - Solução correta!</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center rounded-md bg-destructive/10 px-2 py-1 text-xs font-medium text-destructive ring-1 ring-inset ring-destructive/20">WA</span>
                            <span class="text-sm"><strong>Wrong Answer</strong> - Saída incorreta</span>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center rounded-md bg-warning/10 px-2 py-1 text-xs font-medium text-warning ring-1 ring-inset ring-warning/20">TLE</span>
                            <span class="text-sm"><strong>Time Limit</strong> - Tempo excedido</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center rounded-md bg-primary/10 px-2 py-1 text-xs font-medium text-primary ring-1 ring-inset ring-primary/20">CE</span>
                            <span class="text-sm"><strong>Compile Error</strong> - Erro de compilação</span>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center rounded-md bg-muted px-2 py-1 text-xs font-medium text-muted-foreground ring-1 ring-inset ring-border">RTE</span>
                            <span class="text-sm"><strong>Runtime Error</strong> - Erro de execução</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center rounded-md bg-muted px-2 py-1 text-xs font-medium text-muted-foreground ring-1 ring-inset ring-border">MLE</span>
                            <span class="text-sm"><strong>Memory Limit</strong> - Memória excedida</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card overflow-hidden">
            <div class="border-b border-border px-6 py-4">
                <h3 class="text-lg font-semibold flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    Estatísticas
                </h3>
            </div>
            <div class="p-6 text-center">
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <div class="text-3xl font-bold text-success">{{ $acceptedCount ?? 0 }}</div>
                        <div class="text-sm text-muted-foreground">Aceitas</div>
                    </div>
                    <div>
                        <div class="text-3xl font-bold text-primary">{{ $totalCount ?? 0 }}</div>
                        <div class="text-sm text-muted-foreground">Total</div>
                    </div>
                </div>
                <div class="border-t border-border pt-4">
                    <p class="text-sm text-muted-foreground">
                        Taxa de acerto: <strong class="text-foreground">{{ $totalCount > 0 ? round(($acceptedCount / $totalCount) * 100) : 0 }}%</strong>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
