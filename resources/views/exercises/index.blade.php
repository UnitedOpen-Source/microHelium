@extends('layouts.app')

@section('title', 'Problemas')

@section('description', 'Explore os desafios da competição e coloque sua solução à prova.')

@section('content')
<div class="space-y-6">
    <!-- Problems List -->
    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <div class="flex items-center gap-3">
                <div class="p-2 bg-primary-soft rounded-lg">
                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <div>
                    <h2 class="text-xl font-semibold text-foreground">Lista de Problemas</h2>
                    <p class="text-sm text-muted-foreground">
                        @if($contest)
                            {{ $contest->name }} &mdash; selecione um problema para ver o enunciado e enviar sua solução
                        @else
                            Nenhuma competição ativa no momento
                        @endif
                    </p>
                </div>
            </div>
        </div>

        @include('partials.table-filter', ['tableId' => 'exercises-index-table', 'searchLabel' => 'problemas', 'difficulty' => false])
        <div class="overflow-x-auto">
            <table id="exercises-index-table" class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider w-16">#</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Problema</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider w-32">Limites</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider w-24">Resolvido</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider w-32">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($problems as $problem)
                    <tr class="hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-4">
                            <span class="problem-marker"><span aria-hidden="true" class="problem-swatch" style="background-color: {{ preg_match('/^#[0-9a-fA-F]{6}$/', $problem->color_hex ?? '') ? $problem->color_hex : 'var(--color-primary)' }}"></span>{{ $problem->short_name }}</span>
                        </td>
                        <td class="px-4 py-4">
                            <a href="{{ route('exercise.show', $problem) }}" class="font-semibold text-foreground hover:text-primary transition-colors">
                                {{ $problem->name }}
                            </a>
                            @if($problem->description)
                            <p class="text-sm text-muted-foreground mt-1">{{ Str::limit($problem->description, 80) }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-4 text-sm text-muted-foreground">
                            {{ $problem->time_limit }}s / {{ $problem->memory_limit }}MB
                        </td>
                        <td class="px-4 py-4">
                            @if($solvedProblemIds->contains($problem->id))
                                <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium bg-success-soft text-success rounded">
                                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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
                                <a href="{{ route('exercise.show', $problem) }}" class="px-3 py-1.5 text-xs font-medium bg-muted text-foreground rounded hover:bg-accent transition-colors">
                                    Ver
                                </a>
                                <a href="{{ route('exercise.submit', $problem) }}" class="px-3 py-1.5 text-xs font-medium bg-primary text-primary-foreground rounded hover:bg-primary-hover transition-colors">
                                    Enviar
                                </a>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center">
                            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-muted-foreground/50 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <p class="text-muted-foreground">Nenhum problema cadastrado ainda</p>
                        </td>
                    </tr>
                    @endforelse
                <tr id="exercises-index-table-empty" hidden><td colspan="5" class="text-center text-muted-foreground">Nenhum resultado para estes filtros. Tente outro termo ou limpe a busca.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
