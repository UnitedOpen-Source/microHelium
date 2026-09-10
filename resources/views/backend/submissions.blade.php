@extends('layouts.app')

@section('title', 'Gerenciar submissões')

@section('description', 'Acompanhe os envios e resultados da competição.')

@section('content')
<div class="space-y-6">
    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-foreground">Histórico de envios</h2>
                    <p class="text-sm text-muted-foreground mt-1">Todas as submissoes da competicao</p>
                </div>
            </div>
        </div>

        @include('partials.table-filter', ['tableId' => 'backend-submissions-table', 'searchLabel' => 'submissões', 'difficulty' => false])
        <div class="overflow-x-auto">
            <table id="backend-submissions-table" class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">#</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Time</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Problema</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Linguagem</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Resultado</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Tempo</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Memória</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Data/Hora</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($submissions as $submission)
                    <tr class="hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-3 text-sm font-bold text-foreground">{{ $submission->id }}</td>
                        <td class="px-4 py-3 text-sm text-foreground">{{ $submission->team_name ?? 'Time #' . $submission->user_id }}</td>
                        <td class="px-4 py-3 text-sm text-foreground">{{ $submission->problem_name ?? 'Problema #' . $submission->problem_id }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 text-xs font-medium bg-muted text-muted-foreground rounded">
                                {{ strtoupper($submission->language ?? '-') }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            @include('partials.verdict', ['code' => $submission->result, 'status' => $submission->status ?? null, 'compact' => false])
                        </td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $submission->time ?? '-' }}s</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $submission->memory ?? '-' }}KB</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $submission->created_at ?? '-' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <a href="{{ route('submission.show', $submission->id) }}" class="p-1.5 text-info hover:bg-info-soft rounded transition-colors" aria-label="Ver código da submissão #{{ $submission->id }}" title="Ver código">
                                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </a>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="px-4 py-12 text-center">
                            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-muted-foreground/50 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                            </svg>
                            <p class="text-muted-foreground">Nenhuma submissao ainda</p>
                        </td>
                    </tr>
                    @endforelse
                <tr id="backend-submissions-table-empty" hidden><td colspan="9" class="text-center text-muted-foreground">Nenhum resultado para estes filtros. Tente outro termo ou limpe a busca.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-card rounded-lg border border-border shadow-sm p-5 text-center">
            <div class="inline-flex items-center justify-center w-10 h-10 bg-info-soft rounded-lg mb-2">
                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-info" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                </svg>
            </div>
            <p class="text-xs text-muted-foreground">Total</p>
            <p class="text-2xl font-bold text-foreground">{{ $submissions->count() }}</p>
        </div>
        <div class="bg-card rounded-lg border border-border shadow-sm p-5 text-center">
            <div class="inline-flex items-center justify-center w-10 h-10 bg-success-soft rounded-lg mb-2">
                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <p class="text-xs text-muted-foreground">Aceitas</p>
            <p class="text-2xl font-bold text-success">{{ $submissions->where('result', 'AC')->count() }}</p>
        </div>
        <div class="bg-card rounded-lg border border-border shadow-sm p-5 text-center">
            <div class="inline-flex items-center justify-center w-10 h-10 bg-destructive-soft rounded-lg mb-2">
                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-destructive" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </div>
            <p class="text-xs text-muted-foreground">Wrong Answer</p>
            <p class="text-2xl font-bold text-destructive">{{ $submissions->where('result', 'WA')->count() }}</p>
        </div>
        <div class="bg-card rounded-lg border border-border shadow-sm p-5 text-center">
            <div class="inline-flex items-center justify-center w-10 h-10 bg-warning-soft rounded-lg mb-2">
                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-warning" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <p class="text-xs text-muted-foreground">Pendentes</p>
            <p class="text-2xl font-bold text-warning">{{ $submissions->filter(fn ($submission) => !$submission->result)->count() }}</p>
        </div>
    </div>
</div>
@endsection
