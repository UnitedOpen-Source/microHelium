@extends('layouts.app')

@section('title', 'Julgar Submissões')

@section('content')
<div class="space-y-6">
    @if(session('success'))
    <div class="px-4 py-3 bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 rounded-lg text-sm">
        {{ session('success') }}
    </div>
    @endif

    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <h2 class="text-xl font-semibold text-foreground">Pendentes de Julgamento</h2>
            <p class="text-sm text-muted-foreground">{{ $contest?->name ?? 'Nenhum contest ativo' }}</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Run</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Time</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Problema</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Linguagem</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Julgar</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($pendingRuns as $run)
                    <tr class="hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-3 font-mono text-sm">#{{ $run->run_number }}</td>
                        <td class="px-4 py-3 text-sm">{{ $run->user->fullname ?? $run->user->username }}</td>
                        <td class="px-4 py-3 text-sm">{{ $run->problem->short_name }} - {{ $run->problem->name }}</td>
                        <td class="px-4 py-3 text-sm">{{ $run->language->name }}</td>
                        <td class="px-4 py-3">
                            <form action="{{ route('judge.runs.judge', $run) }}" method="POST" class="flex items-center gap-2">
                                @csrf
                                <select name="answer_id" required class="text-sm px-2 py-1.5 bg-background border border-border rounded-lg">
                                    <option value="">Veredito...</option>
                                    @foreach($answers as $answer)
                                        <option value="{{ $answer->id }}">{{ $answer->name }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="px-3 py-1.5 text-xs font-medium bg-primary text-primary-foreground rounded hover:bg-primary/90 transition-colors">
                                    Confirmar
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center text-muted-foreground">Nenhuma submissao pendente</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <h2 class="text-xl font-semibold text-foreground">Julgadas Recentemente</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Run</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Time</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Problema</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Veredito</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($judgedRuns as $run)
                    <tr class="hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-3 font-mono text-sm">#{{ $run->run_number }}</td>
                        <td class="px-4 py-3 text-sm">{{ $run->user->fullname ?? $run->user->username }}</td>
                        <td class="px-4 py-3 text-sm">{{ $run->problem->short_name }} - {{ $run->problem->name }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 text-xs font-medium rounded {{ $run->answer?->is_accepted ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' }}">
                                {{ $run->answer->short_name ?? '-' }}
                            </span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-4 py-12 text-center text-muted-foreground">Nenhuma submissao julgada ainda</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
