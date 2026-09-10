@extends('layouts.app')

@section('title', 'Tarefas')

@section('content')
<div class="space-y-6">
    {{-- layouts/app.blade.php already renders session('success')/session('error') globally --}}

    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <h2 class="text-xl font-semibold text-foreground">Tarefas</h2>
            <p class="text-sm text-muted-foreground">{{ $contest?->name ?? 'Nenhum contest ativo' }} &mdash; entrega de balões, impressão e outras tarefas de staff</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">#</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Descricao</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Time</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Concluida por</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Acao</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($tasks as $task)
                    <tr class="hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-3 font-mono text-sm">
                            <span @if($task->color_hex) style="color: {{ $task->color_hex }}" @endif>#{{ $task->task_number }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm">{{ $task->description }}</td>
                        <td class="px-4 py-3 text-sm">{{ $task->user->fullname ?? $task->user->username }}</td>
                        <td class="px-4 py-3">
                            @if($task->isDone())
                                <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 rounded">Concluida</span>
                            @elseif($task->status === 'processing')
                                <span class="px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400 rounded">Em andamento</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium bg-muted text-muted-foreground rounded">Pendente</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $task->staff->fullname ?? $task->staff->username ?? '-' }}</td>
                        <td class="px-4 py-3">
                            @unless($task->isDone())
                            <form action="{{ route('staff.tasks.complete', $task) }}" method="POST">
                                @csrf
                                <button type="submit" class="px-3 py-1.5 text-xs font-medium bg-primary text-primary-foreground rounded hover:bg-primary/90 transition-colors">
                                    Marcar concluida
                                </button>
                            </form>
                            @else
                            <span class="text-muted-foreground text-sm">-</span>
                            @endunless
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-muted-foreground">Nenhuma tarefa cadastrada</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
