@extends('layouts.app')

@section('title', 'Gerenciar Problemas')

@section('content')
<div class="space-y-6">
    {{-- layouts/app.blade.php already renders session('success')/session('error') globally --}}

    <div class="bg-card rounded-lg border border-border shadow-sm p-6">
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h2 class="text-xl font-semibold text-foreground">Gerenciar Problemas</h2>
                <p class="text-sm text-muted-foreground">Problemas reais (com casos de teste) usados pelo julgamento automatico</p>
            </div>

            @if($contests->isNotEmpty())
            <form method="GET" action="{{ route('backend.exercises') }}" class="flex items-center gap-2">
                <label for="contest_id" class="text-sm text-muted-foreground">Contest:</label>
                <select name="contest_id" id="contest_id" onchange="this.form.submit()" class="text-sm px-3 py-2 bg-background border border-border rounded-lg">
                    @foreach($contests as $c)
                        <option value="{{ $c->id }}" @selected($contest && $contest->id === $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </form>
            @endif
        </div>
    </div>

    @if(!$contest)
    <div class="bg-card rounded-lg border border-border shadow-sm p-6 text-center text-muted-foreground">
        Nenhum contest cadastrado ainda. Crie um pelo <a href="{{ route('backend.contest-wizard') }}" class="text-primary hover:underline">assistente</a> primeiro.
    </div>
    @else
    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <h3 class="font-semibold text-foreground">Problemas em "{{ $contest->name }}"</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">#</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Nome</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Limites</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Casos de Teste</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($problems as $problem)
                    <tr>
                        <td class="px-4 py-3 font-bold" style="color: {{ $problem->color_hex ?? 'inherit' }}">{{ $problem->short_name }}</td>
                        <td class="px-4 py-3 text-sm">{{ $problem->name }}</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $problem->time_limit }}s / {{ $problem->memory_limit }}MB</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $problem->test_cases_count }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">Nenhum problema neste contest ainda</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <h3 class="font-semibold text-foreground">Adicionar do Banco de Problemas</h3>
            <p class="text-sm text-muted-foreground">Cada problema adicionado ganha um caso de teste inicial a partir do exemplo cadastrado no banco.</p>
        </div>
        <form method="POST" action="{{ route('backend.exercises') }}" class="p-6 space-y-4">
            @csrf
            <input type="hidden" name="contest_id" value="{{ $contest->id }}">

            @if($availableBankItems->isEmpty())
            <p class="text-sm text-muted-foreground">Todos os problemas ativos do banco ja foram adicionados a este contest.</p>
            @else
            <div class="space-y-2 max-h-96 overflow-y-auto">
                @foreach($availableBankItems as $item)
                <label class="flex items-center gap-3 p-3 border border-border rounded-lg hover:bg-muted/50 transition-colors cursor-pointer">
                    <input type="checkbox" name="problems[]" value="{{ $item->id }}" class="rounded border-border">
                    <div>
                        <span class="font-medium text-sm">{{ $item->name }}</span>
                        <span class="text-xs text-muted-foreground ml-2">{{ $item->code }} &middot; {{ $item->difficulty_label }}</span>
                    </div>
                </label>
                @endforeach
            </div>
            <button type="submit" class="px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors">
                Adicionar Selecionados
            </button>
            @endif
        </form>
    </div>
    @endif
</div>
@endsection
