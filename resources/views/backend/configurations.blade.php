@extends('layouts.app')

@section('title', 'Configurações')


@section('description', 'Configure as maratonas e controle o andamento dos eventos.')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Main Content -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Lista de competicoes -->
        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-6 border-b border-border">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-semibold text-foreground">Configurações da Maratona</h2>
                        <p class="text-sm text-muted-foreground mt-1">Confira a agenda e abra as operações do evento que deseja gerenciar.</p>
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
                        @forelse ($contests as $contest)
                        <tr class="hover:bg-muted/50 transition-colors">
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ $contest->id }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-foreground"><a class="feature-link" href="{{ route('backend.contest.operations', $contest) }}">{{ $contest->name }}</a><span class="block text-xs text-muted-foreground mt-1">{{ $contest->isFinalized() ? 'Finalizada' : ($contest->isRunning() ? 'Em andamento' : ($contest->start_time?->isFuture() ? 'Agendada' : 'Fora do período de prova')) }}{{ $contest->is_active ? ' · Selecionada' : '' }}{{ $contest->isFrozen() ? ' · Placar congelado' : '' }}</span></td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ Str::limit($contest->description ?? '-', 50) }}</td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ $contest->start_time ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ $contest->start_time ? \Carbon\Carbon::parse($contest->start_time)->addMinutes((int) $contest->duration) : '-' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <a class="button-secondary" href="{{ route('backend.contest.operations', $contest) }}">Operações<span class="sr-only">: {{ $contest->name }}</span></a>
                                    @if(!$contest->is_active && !$contest->isFinalized())
                                    <form data-confirm="Selecionar {{ $contest->name }} como competição ativa? A competição selecionada anteriormente será desativada." action="/backend/contest/{{ $contest->id }}/activate" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" class="p-1.5 text-success hover:bg-success-soft rounded transition-colors" aria-label="Selecionar {{ $contest->name }} como competição ativa" title="Selecionar competição">
                                            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </button>
                                    </form>
                                    @endif
                                    <a href="/backend/contest/{{ $contest->id }}/edit" class="p-1.5 text-warning hover:bg-warning-soft rounded transition-colors" aria-label="Editar {{ $contest->name }}" title="Editar">
                                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </a>
                                    <form action="/backend/contest/{{ $contest->id }}/delete" method="POST" class="inline" data-confirm="Tem certeza que deseja excluir esta maratona?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="p-1.5 text-destructive hover:bg-destructive-soft rounded transition-colors" aria-label="Excluir {{ $contest->name }}" title="Excluir">
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

        <section class="surface p-6">
            <h2 class="text-lg font-semibold">Operação e encerramento</h2>
            <p class="text-sm text-muted-foreground mt-3">Abra as operações de uma competição para consultar pendências, revelar o placar e finalizar. Cada ação identifica o evento afetado.</p>
            <a href="/scoreboard/export" class="button-secondary mt-4">Exportar meu placar (CSV)</a>
            <a href="{{ route('backend.tools') }}" class="button-secondary mt-4">Ferramentas da organização</a>
        </section>
    </div>
</div>

@endsection
