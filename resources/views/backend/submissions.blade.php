@extends('layouts.app')

@section('title', 'Gerenciar Submissoes')

@section('content')
<div class="space-y-6">
    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-foreground">Gerenciar Submissoes</h2>
                    <p class="text-sm text-muted-foreground mt-1">Todas as submissoes da competicao</p>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="p-4 border-b border-border bg-muted/30">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <select class="px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Todos os Times</option>
                </select>
                <select class="px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Todos os Problemas</option>
                </select>
                <select class="px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="">Todos os Resultados</option>
                    <option value="AC">Aceito (AC)</option>
                    <option value="WA">Wrong Answer (WA)</option>
                    <option value="TLE">Time Limit (TLE)</option>
                    <option value="CE">Compile Error (CE)</option>
                    <option value="RTE">Runtime Error (RTE)</option>
                    <option value="pending">Pendente</option>
                </select>
                <button class="px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors">
                    Filtrar
                </button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">#</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Time</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Problema</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Linguagem</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Resultado</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Tempo</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Memoria</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Data/Hora</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Acoes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($submissions as $submission)
                    <tr class="hover:bg-muted/50 transition-colors {{ ($submission->result ?? '') == 'AC' ? 'bg-green-50 dark:bg-green-900/10' : (($submission->result ?? '') == 'WA' ? 'bg-red-50 dark:bg-red-900/10' : '') }}">
                        <td class="px-4 py-3 text-sm font-bold text-foreground">{{ $submission->id }}</td>
                        <td class="px-4 py-3 text-sm text-foreground">{{ $submission->team_name ?? 'Time #' . $submission->team_id }}</td>
                        <td class="px-4 py-3 text-sm text-foreground">{{ $submission->problem_name ?? 'Problema #' . $submission->exercise_id }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 text-xs font-medium bg-muted text-muted-foreground rounded">
                                {{ strtoupper($submission->language ?? '-') }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            @switch($submission->result ?? 'pending')
                                @case('AC')
                                    <span class="px-2 py-1 text-xs font-bold bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 rounded">AC</span>
                                    @break
                                @case('WA')
                                    <span class="px-2 py-1 text-xs font-bold bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 rounded">WA</span>
                                    @break
                                @case('TLE')
                                    <span class="px-2 py-1 text-xs font-bold bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400 rounded">TLE</span>
                                    @break
                                @case('CE')
                                    <span class="px-2 py-1 text-xs font-bold bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400 rounded">CE</span>
                                    @break
                                @case('RTE')
                                    <span class="px-2 py-1 text-xs font-bold bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400 rounded">RTE</span>
                                    @break
                                @case('MLE')
                                    <span class="px-2 py-1 text-xs font-bold bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400 rounded">MLE</span>
                                    @break
                                @case('pending')
                                    <span class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400 rounded">
                                        <svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Julgando
                                    </span>
                                    @break
                                @default
                                    <span class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400 rounded">{{ $submission->result }}</span>
                            @endswitch
                        </td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $submission->time ?? '-' }}s</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $submission->memory ?? '-' }}KB</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $submission->created_at ?? '-' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <button class="p-1.5 text-blue-600 hover:bg-blue-100 dark:hover:bg-blue-900/30 rounded transition-colors" title="Ver codigo">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>
                                <button class="p-1.5 text-yellow-600 hover:bg-yellow-100 dark:hover:bg-yellow-900/30 rounded transition-colors" title="Rejulgar">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="px-4 py-12 text-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-muted-foreground/50 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                            </svg>
                            <p class="text-muted-foreground">Nenhuma submissao ainda</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-card rounded-lg border border-border shadow-sm p-5 text-center">
            <div class="inline-flex items-center justify-center w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg mb-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                </svg>
            </div>
            <p class="text-xs text-muted-foreground">Total</p>
            <p class="text-2xl font-bold text-foreground">{{ $submissions->count() }}</p>
        </div>
        <div class="bg-card rounded-lg border border-border shadow-sm p-5 text-center">
            <div class="inline-flex items-center justify-center w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-lg mb-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <p class="text-xs text-muted-foreground">Aceitas</p>
            <p class="text-2xl font-bold text-green-600">{{ $submissions->where('result', 'AC')->count() }}</p>
        </div>
        <div class="bg-card rounded-lg border border-border shadow-sm p-5 text-center">
            <div class="inline-flex items-center justify-center w-10 h-10 bg-red-100 dark:bg-red-900/30 rounded-lg mb-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </div>
            <p class="text-xs text-muted-foreground">Wrong Answer</p>
            <p class="text-2xl font-bold text-red-600">{{ $submissions->where('result', 'WA')->count() }}</p>
        </div>
        <div class="bg-card rounded-lg border border-border shadow-sm p-5 text-center">
            <div class="inline-flex items-center justify-center w-10 h-10 bg-yellow-100 dark:bg-yellow-900/30 rounded-lg mb-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-yellow-600 dark:text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <p class="text-xs text-muted-foreground">Pendentes</p>
            <p class="text-2xl font-bold text-yellow-600">{{ $submissions->where('result', 'pending')->count() }}</p>
        </div>
    </div>
</div>
@endsection
