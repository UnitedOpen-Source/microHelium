@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<!-- Stats Row -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="rounded-xl border border-border bg-card p-6 text-center">
        <div class="text-4xl mb-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 mx-auto text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
        </div>
        <div class="text-3xl font-bold text-foreground">{{ $totalProblems ?? 0 }}</div>
        <div class="text-sm text-muted-foreground">Problemas</div>
    </div>

    <div class="rounded-xl border border-border bg-card p-6 text-center">
        <div class="text-4xl mb-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 mx-auto text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
            </svg>
        </div>
        <div class="text-3xl font-bold text-foreground">{{ $totalTeams ?? 0 }}</div>
        <div class="text-sm text-muted-foreground">Times</div>
    </div>

    <div class="rounded-xl border border-border bg-card p-6 text-center">
        <div class="text-4xl mb-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 mx-auto text-warning" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
            </svg>
        </div>
        <div class="text-3xl font-bold text-foreground">{{ $totalSubmissions ?? 0 }}</div>
        <div class="text-sm text-muted-foreground">Submissões</div>
    </div>

    <div class="rounded-xl border border-border bg-card p-6 text-center">
        <div class="text-4xl mb-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 mx-auto text-destructive" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        </div>
        <div class="text-3xl font-bold text-foreground">{{ $acceptedSubmissions ?? 0 }}</div>
        <div class="text-sm text-muted-foreground">Aceitas</div>
    </div>
</div>

<!-- Main Content -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Welcome & Submissions Column -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Welcome Card -->
        <div class="rounded-xl border border-border bg-card overflow-hidden">
            <div class="border-b border-border px-6 py-4">
                <h3 class="text-lg font-semibold flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    Bem-vindo ao MicroHelium
                </h3>
                <p class="text-sm text-muted-foreground">Sistema de Gerenciamento de Maratonas de Programação</p>
            </div>
            <div class="p-6">
                <p class="text-muted-foreground mb-6">
                    Plataforma completa para organização de competições de programação no estilo ICPC.
                    Submeta suas soluções, acompanhe o placar em tempo real e comunique-se com os juízes.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h4 class="font-semibold flex items-center gap-2 mb-3">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                            Como Participar
                        </h4>
                        <ol class="list-decimal list-inside space-y-1 text-sm text-muted-foreground">
                            <li>Acesse a lista de <a href="/exercises" class="text-primary hover:underline">Problemas</a></li>
                            <li>Leia o enunciado com atenção</li>
                            <li>Desenvolva e teste sua solução</li>
                            <li>Submeta seu código fonte</li>
                            <li>Acompanhe o resultado no <a href="/scoreboard" class="text-primary hover:underline">Placar</a></li>
                        </ol>
                    </div>
                    <div>
                        <h4 class="font-semibold flex items-center gap-2 mb-3">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Vereditos
                        </h4>
                        <div class="space-y-2 text-sm">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center rounded-md bg-success/10 px-2 py-1 text-xs font-medium text-success ring-1 ring-inset ring-success/20">AC</span>
                                <span class="text-muted-foreground">Aceito - Solução correta</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center rounded-md bg-destructive/10 px-2 py-1 text-xs font-medium text-destructive ring-1 ring-inset ring-destructive/20">WA</span>
                                <span class="text-muted-foreground">Resposta Errada</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center rounded-md bg-warning/10 px-2 py-1 text-xs font-medium text-warning ring-1 ring-inset ring-warning/20">TLE</span>
                                <span class="text-muted-foreground">Tempo Limite Excedido</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center rounded-md bg-primary/10 px-2 py-1 text-xs font-medium text-primary ring-1 ring-inset ring-primary/20">CE</span>
                                <span class="text-muted-foreground">Erro de Compilação</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="inline-flex items-center rounded-md bg-muted px-2 py-1 text-xs font-medium text-muted-foreground ring-1 ring-inset ring-border">RTE</span>
                                <span class="text-muted-foreground">Erro de Execução</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Submissions -->
        <div class="rounded-xl border border-border bg-card overflow-hidden">
            <div class="border-b border-border px-6 py-4">
                <h3 class="text-lg font-semibold flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Submissões Recentes
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-border bg-muted/50">
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Time</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Problema</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Linguagem</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Resultado</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Tempo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse($recentSubmissions ?? [] as $submission)
                        <tr class="hover:bg-muted/50 transition-colors">
                            <td class="px-4 py-3 text-sm">{{ $submission->id }}</td>
                            <td class="px-4 py-3 text-sm font-medium">{{ $submission->team_name }}</td>
                            <td class="px-4 py-3 text-sm">{{ $submission->problem_name }}</td>
                            <td class="px-4 py-3 text-sm">{{ $submission->language }}</td>
                            <td class="px-4 py-3 text-sm">
                                @if($submission->result == 'AC')
                                    <span class="inline-flex items-center rounded-md bg-success/10 px-2 py-1 text-xs font-medium text-success ring-1 ring-inset ring-success/20">AC</span>
                                @elseif($submission->result == 'WA')
                                    <span class="inline-flex items-center rounded-md bg-destructive/10 px-2 py-1 text-xs font-medium text-destructive ring-1 ring-inset ring-destructive/20">WA</span>
                                @elseif($submission->result == 'TLE')
                                    <span class="inline-flex items-center rounded-md bg-warning/10 px-2 py-1 text-xs font-medium text-warning ring-1 ring-inset ring-warning/20">TLE</span>
                                @else
                                    <span class="inline-flex items-center rounded-md bg-muted px-2 py-1 text-xs font-medium text-muted-foreground ring-1 ring-inset ring-border">{{ $submission->result }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ $submission->time }}s</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">
                                Nenhuma submissão ainda
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="space-y-6">
        <!-- Contest Info -->
        <div class="rounded-xl border border-border bg-card overflow-hidden">
            <div class="border-b border-border px-6 py-4">
                <h3 class="text-lg font-semibold flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Competição
                </h3>
            </div>
            <div class="p-6 text-center">
                <div class="text-4xl font-mono font-bold text-foreground mb-2" id="main-timer">
                    <contest-timer></contest-timer>
                </div>
                <p class="text-sm text-muted-foreground mb-4">Tempo restante</p>
                <div class="border-t border-border pt-4">
                    <p class="text-sm">
                        <span class="text-muted-foreground">Status:</span>
                        <span class="inline-flex items-center rounded-md bg-success/10 px-2 py-1 text-xs font-medium text-success ring-1 ring-inset ring-success/20">Em andamento</span>
                    </p>
                </div>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="rounded-xl border border-border bg-card overflow-hidden">
            <div class="border-b border-border px-6 py-4">
                <h3 class="text-lg font-semibold flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                    </svg>
                    Acesso Rápido
                </h3>
            </div>
            <div class="p-4 space-y-2">
                <a href="/exercises" class="flex items-center justify-center gap-2 w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground hover:bg-primary/90 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    Ver Problemas
                </a>
                <a href="/scoreboard" class="flex items-center justify-center gap-2 w-full rounded-lg bg-success px-4 py-2.5 text-sm font-medium text-success-foreground hover:bg-success/90 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    Ver Placar
                </a>
                <a href="/clarifications" class="flex items-center justify-center gap-2 w-full rounded-lg bg-warning px-4 py-2.5 text-sm font-medium text-warning-foreground hover:bg-warning/90 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                    </svg>
                    Clarificações
                </a>
                <a href="/ajuda" class="flex items-center justify-center gap-2 w-full rounded-lg border border-border px-4 py-2.5 text-sm font-medium text-foreground hover:bg-accent transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Ajuda
                </a>
            </div>
        </div>

        <!-- Languages -->
        <div class="rounded-xl border border-border bg-card overflow-hidden">
            <div class="border-b border-border px-6 py-4">
                <h3 class="text-lg font-semibold flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    Linguagens
                </h3>
            </div>
            <div class="p-6">
                <ul class="space-y-2 text-sm">
                    <li class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <span><strong>C</strong> - GCC 11</span>
                    </li>
                    <li class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <span><strong>C++</strong> - G++ 11</span>
                    </li>
                    <li class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <span><strong>Java</strong> - OpenJDK 17</span>
                    </li>
                    <li class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <span><strong>Python</strong> - Python 3.11</span>
                    </li>
                </ul>
                <div class="border-t border-border mt-4 pt-4">
                    <p class="text-xs text-muted-foreground">
                        <strong>Limites:</strong> 10s tempo, 512MB memória
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
