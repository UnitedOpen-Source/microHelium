@extends('layouts.app')

@section('title', 'Gerenciar Problemas')

@section('description', 'Selecione a competição, confira os desafios e adicione problemas do acervo.')
@section('page-actions')<a href="{{ route('backend.problem-bank') }}" class="button-secondary">Abrir banco de problemas</a>@endsection

@section('content')
<div class="space-y-6">
    {{-- layouts/app.blade.php already renders session('success')/session('error') globally --}}

    <div class="bg-card rounded-lg border border-border shadow-sm p-6">
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h2 class="text-xl font-semibold text-foreground">Competição selecionada</h2>
                <p class="text-sm text-muted-foreground">Os problemas e limites abaixo são utilizados na avaliação das soluções.</p>
            </div>

            @if($contests->isNotEmpty())
            <form method="GET" action="{{ route('backend.exercises') }}" class="flex flex-wrap items-end gap-2">
                <label for="contest_id" class="text-sm text-muted-foreground">Competição</label>
                <select name="contest_id" id="contest_id" class="text-sm px-3 py-2 bg-background border border-border rounded-lg">
                    @foreach($contests as $c)
                        <option value="{{ $c->id }}" @selected($contest && $contest->id === $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="button-secondary">Exibir problemas</button>
            </form>
            @endif
        </div>
    </div>

    @if(!$contest)
    <div class="bg-card rounded-lg border border-border shadow-sm p-6 text-center text-muted-foreground">
        Nenhuma competição cadastrada ainda. Crie uma pelo <a href="{{ route('backend.contest-wizard') }}" class="text-primary hover:underline">assistente</a> primeiro.
    </div>
    @else
    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <h2 class="font-semibold text-foreground">Problemas em "{{ $contest->name }}"</h2>
        </div>
        @include('partials.table-filter', ['tableId' => 'contest-problems-table', 'searchLabel' => 'problemas', 'difficulty' => false])
        <div class="overflow-x-auto">
            <table id="contest-problems-table" class="w-full">
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
                        <td class="px-4 py-3 font-bold text-foreground">{{ $problem->short_name }}</td>
                        <td class="px-4 py-3 text-sm">{{ $problem->name }}</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $problem->time_limit }}s / {{ $problem->memory_limit }}MB</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $problem->test_cases_count }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">Nenhum problema nesta competição ainda</td>
                    </tr>
                    @endforelse
                    <tr id="contest-problems-table-empty" hidden><td colspan="4" class="text-center text-muted-foreground">Nenhum problema corresponde à busca. Limpe o filtro para rever a lista.</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <h2 class="font-semibold text-foreground">Adicionar do Banco de Problemas</h2>
            <p class="text-sm text-muted-foreground">Cada problema adicionado ganha um caso de teste inicial a partir do exemplo cadastrado no banco.</p>
        </div>
        <form method="POST" action="{{ route('backend.exercises') }}" class="p-6 space-y-4">
            @csrf
            <input type="hidden" name="contest_id" value="{{ $contest->id }}">

            @if($availableBankItems->isEmpty())
            <p class="text-sm text-muted-foreground">Não há problemas disponíveis para adicionar. Importe novos problemas no banco para ampliar a competição.</p>
            @else
            <p id="bank-selection-hint" class="text-sm text-muted-foreground">Selecione pelo menos um problema para adicionar à competição.</p>
            <div role="group" aria-label="Problemas disponíveis para adicionar" aria-describedby="bank-selection-hint" class="space-y-2 max-h-96 overflow-y-auto">
                @foreach($availableBankItems as $item)
                <label class="flex items-center gap-3 p-3 focus-within:ring-2 focus-within:ring-primary border border-border rounded-lg hover:bg-muted/50 transition-colors cursor-pointer">
                    <input type="checkbox" name="problems[]" value="{{ $item->id }}" @checked(in_array($item->id, old('problems', []))) class="rounded border-border">
                    <div>
                        <span class="font-medium text-sm">{{ $item->name }}</span>
                        <span class="text-xs text-muted-foreground ml-2">{{ $item->code }} &middot; {{ $item->difficulty_label }}</span>
                    </div>
                </label>
                @endforeach
            </div>
            <button type="submit" class="px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary-hover transition-colors">
                Adicionar Selecionados
            </button>
            @endif
        </form>
    </div>
    @endif
</div>
@endsection
