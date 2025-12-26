@extends('layouts.app')

@section('title', 'Problemas')

@php
    $availableLanguages = collect(\App\Models\Language::getDefaultLanguages())
        ->filter(fn($l) => $l['is_active'] ?? true)
        ->pluck('name')
        ->implode(', ');
@endphp

@section('content')
<div class="space-y-6">
    <!-- Problems List -->
    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <div class="flex items-center gap-3">
                <div class="p-2 bg-primary/10 rounded-lg">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <div>
                    <h2 class="text-xl font-semibold text-foreground">Lista de Problemas</h2>
                    <p class="text-sm text-muted-foreground">Selecione um problema para ver o enunciado e submeter sua solucao</p>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider w-16">#</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Problema</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider w-28">Categoria</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider w-28">Dificuldade</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider w-20">Pontos</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider w-24">Resolvido</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider w-32">Acao</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($exercises as $index => $exercise)
                    <tr class="hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-4">
                            <span class="text-2xl font-bold text-primary">{{ chr(65 + $index) }}</span>
                        </td>
                        <td class="px-4 py-4">
                            <a href="/exercise/{{ $exercise->exercise_id }}" class="font-semibold text-foreground hover:text-primary transition-colors">
                                {{ $exercise->exerciseName }}
                            </a>
                            @if($exercise->description)
                            <p class="text-sm text-muted-foreground mt-1">{{ Str::limit($exercise->description, 60) }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-4">
                            <span class="px-2 py-1 text-xs font-medium bg-muted text-muted-foreground rounded">
                                {{ $exercise->category ?? 'Geral' }}
                            </span>
                        </td>
                        <td class="px-4 py-4">
                            @if(($exercise->difficulty ?? 'medium') == 'easy')
                                <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 rounded">Facil</span>
                            @elseif(($exercise->difficulty ?? 'medium') == 'medium')
                                <span class="px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400 rounded">Medio</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 rounded">Dificil</span>
                            @endif
                        </td>
                        <td class="px-4 py-4">
                            <span class="font-semibold text-foreground">{{ $exercise->score ?? 100 }}</span>
                            <span class="text-muted-foreground text-sm">pts</span>
                        </td>
                        <td class="px-4 py-4">
                            @if($exercise->solved ?? false)
                                <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 rounded">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                    Sim
                                </span>
                            @else
                                <span class="text-muted-foreground">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-4">
                            <div class="flex items-center gap-2">
                                <a href="/exercise/{{ $exercise->exercise_id }}" class="px-3 py-1.5 text-xs font-medium bg-blue-600 text-white rounded hover:bg-blue-700 transition-colors">
                                    Ver
                                </a>
                                <a href="/submit/{{ $exercise->exercise_id }}" class="px-3 py-1.5 text-xs font-medium bg-green-600 text-white rounded hover:bg-green-700 transition-colors">
                                    Enviar
                                </a>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-muted-foreground/50 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <p class="text-muted-foreground">Nenhum problema cadastrado ainda</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Legend -->
    <div class="bg-card rounded-lg border border-border shadow-sm p-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div class="flex items-center gap-2">
                <span class="font-medium text-foreground">Dificuldade:</span>
                <span class="px-2 py-0.5 text-xs bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 rounded">Facil</span>
                <span class="px-2 py-0.5 text-xs bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400 rounded">Medio</span>
                <span class="px-2 py-0.5 text-xs bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 rounded">Dificil</span>
            </div>
            <div class="flex items-center gap-2 text-muted-foreground">
                <span class="font-medium text-foreground">Limites:</span>
                Tempo: 10s | Memoria: 512MB
            </div>
            <div class="flex items-center gap-2 text-muted-foreground">
                <span class="font-medium text-foreground">Linguagens:</span>
                <span class="truncate">{{ $availableLanguages }}</span>
            </div>
        </div>
    </div>
</div>
@endsection
