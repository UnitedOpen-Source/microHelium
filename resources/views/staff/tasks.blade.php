@extends('layouts.app')

@section('title', 'Tarefas')

@section('description', 'Organize as entregas, impressões e solicitações dos participantes.')

@section('content')

{{-- Issue #143: balloon colour is per task and dynamic, and a style=""
     attribute cannot carry a CSP nonce. One nonced block with a rule per
     task replaces them, which is what lets style-src drop 'unsafe-inline'.
     The hex is validated here rather than trusted into a stylesheet. --}}
@if($tasks->isNotEmpty())
<style nonce="{{ $cspNonce ?? '' }}">
@foreach($tasks as $task)
@if(preg_match('/^#[0-9a-fA-F]{6}$/', $task->color_hex ?? ''))
.balloon-swatch-{{ $task->id }}{background-color:{{ $task->color_hex }}}
@endif
@endforeach
</style>
@endif
<div class="space-y-6">

    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <h2 class="text-xl font-semibold text-foreground">Tarefas</h2>
            <p class="text-sm text-muted-foreground">{{ $contest?->name ?? 'Nenhum contest ativo' }} &mdash; entrega de balões, impressão e outras tarefas de staff</p>
        </div>
        @include('partials.table-filter', ['tableId' => 'staff-tasks-table', 'searchLabel' => 'registros'])
        <div class="overflow-x-auto">
            <table id="staff-tasks-table" class="w-full">
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
                            <span class="font-mono font-semibold text-foreground">#{{ $task->task_number }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            @if($task->color_hex)
                                {{-- Issue #87: the colour is what the staff member
                                     actually looks for when picking the balloon up. --}}
                                <span class="inline-block w-3 h-3 rounded-full align-middle mr-2 border border-border balloon-swatch-{{ $task->id }}"
                                      title="{{ $task->color_name }}"
                                      aria-hidden="true"></span>
                            @endif
                            {{ $task->description }}
                            @if($task->file_path)
                                {{-- Issue #94: the file the team wants printed. --}}
                                <a href="{{ route('staff.tasks.file', $task) }}" class="ml-2 text-primary hover:underline text-xs">
                                    Baixar arquivo<span class="sr-only"> da tarefa #{{ $task->task_number }}</span>
                                </a>
                            @endif
                            @if($task->color_name)
                                <span class="sr-only">Cor do balao: {{ $task->color_name }}.</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm">{{ $task->user->fullname ?? $task->user->username }}</td>
                        <td class="px-4 py-3">
                            @if($task->isDone())
                                <span class="px-2 py-1 text-xs font-medium bg-success-soft text-success rounded">Concluida</span>
                            @elseif($task->status === 'processing')
                                <span class="px-2 py-1 text-xs font-medium bg-warning-soft text-warning rounded">Em andamento</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium bg-muted text-muted-foreground rounded">Pendente</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $task->staff->fullname ?? $task->staff->username ?? '-' }}</td>
                        <td class="px-4 py-3">
                            @unless($task->isDone())
                            <form action="{{ route('staff.tasks.complete', $task) }}" method="POST">
                                @csrf
                                <button type="submit" class="px-3 py-1.5 text-xs font-medium bg-primary text-primary-foreground rounded hover:bg-primary-hover transition-colors">
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
                <tr id="staff-tasks-table-empty" hidden><td colspan="6" class="text-center text-muted-foreground">Nenhum resultado para estes filtros.</td></tr></tbody>
            </table>
        </div>
    </div>
</div>
@endsection
