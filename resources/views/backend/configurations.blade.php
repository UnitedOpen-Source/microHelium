@extends('layouts.app')

@section('title', 'Configurações')


@section('description', 'Configure as maratonas e controle o andamento dos eventos.')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Main Content -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Hackathons List -->
        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-6 border-b border-border">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-semibold text-foreground">Configurações da Maratona</h2>
                        <p class="text-sm text-muted-foreground mt-1">Gerencie as competicoes e hackathons</p>
                    </div>
                    <a href="{{ route('backend.contest-wizard') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary-hover transition-colors">
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Nova maratona
                    </a>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Nome</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Descrição</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Inicio</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Fim</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($hackathons as $hackathon)
                        <tr class="hover:bg-muted/50 transition-colors">
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ $hackathon->hackathon_id }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-foreground">{{ $hackathon->eventName }}</td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ Str::limit($hackathon->description ?? '-', 50) }}</td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ $hackathon->starts_at ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ $hackathon->ends_at ?? '-' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <form action="/backend/contest/{{ $hackathon->hackathon_id }}/activate" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" class="p-1.5 text-success hover:bg-success-soft rounded transition-colors" title="Ativar">
                                            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </button>
                                    </form>
                                    <a href="/backend/contest/{{ $hackathon->hackathon_id }}/edit" class="p-1.5 text-warning hover:bg-warning-soft rounded transition-colors" title="Editar">
                                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </a>
                                    <form action="/backend/contest/{{ $hackathon->hackathon_id }}/delete" method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir esta maratona?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="p-1.5 text-destructive hover:bg-destructive-soft rounded transition-colors" title="Excluir">
                                            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">
                                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto mb-3 text-muted-foreground/50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                </svg>
                                Nenhuma maratona cadastrada
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
        <section class="surface p-6">
            <h2 class="text-lg font-semibold mb-4">Referências do sistema</h2>
            <dl class="space-y-4 text-sm">
                <div><dt class="text-muted-foreground">Plataforma</dt><dd class="font-semibold">{{ config('app.name') }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Tempo padrão</dt><dd class="font-mono">{{ config('autojudge.time_limit', 10) }} s</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted-foreground">Memória padrão</dt><dd class="font-mono">{{ config('autojudge.memory_limit', 512) }} MB</dd></div>
            </dl>
            <p class="text-sm text-muted-foreground mt-5">Para alterar a agenda e as regras de uma maratona, use a ação Editar na lista.</p>
        </section>
        <section class="surface p-6">
            <h2 class="text-lg font-semibold mb-4">Relógio da competição</h2>
            <contest-timer></contest-timer>
            <p class="text-sm text-muted-foreground mt-4">O tempo acompanha a agenda da competição ativa.</p>
        </section>

        <!-- Quick Actions -->
        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-4 border-b border-border">
                <h3 class="font-semibold text-foreground">Ações Rapidas</h3>
            </div>
            <div class="p-4 space-y-2">
                <a href="/scoreboard/export" class="w-full px-4 py-2 bg-info text-info-foreground rounded-lg hover:bg-info-hover transition-colors flex items-center justify-center gap-2">
                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    Exportar Placar (CSV)
                </a>
                <form action="/backend/contest/freeze" method="POST">
                    @csrf
                    <button type="submit" class="w-full px-4 py-2 bg-warning text-warning-foreground rounded-lg hover:bg-warning-hover transition-colors flex items-center justify-center gap-2">
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707" />
                        </svg>
                        Congelar Placar
                    </button>
                </form>
                <form action="/backend/contest/end" method="POST" onsubmit="return confirm('Tem certeza que deseja encerrar a competicao?')">
                    @csrf
                    <button type="submit" class="w-full px-4 py-2 bg-destructive text-destructive-foreground rounded-lg hover:bg-destructive-hover transition-colors flex items-center justify-center gap-2">
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
                        </svg>
                        Encerrar Competicao
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
