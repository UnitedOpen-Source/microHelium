@extends('layouts.app')

@section('title', 'Gerenciar Linguagens')

@section('description', 'Cadastre as linguagens desta competição e ajuste os comandos de compilação e execução usados pelo julgamento.')

@section('content')
<div class="space-y-6">
    {{-- layouts/app.blade.php already renders session('success')/session('error') globally --}}

    <div class="bg-card rounded-lg border border-border shadow-sm p-6">
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h2 class="text-xl font-semibold text-foreground">Linguagens</h2>
                <p class="text-sm text-muted-foreground">Cada competição tem o seu próprio conjunto de linguagens, porque a versão do compilador e as flags mudam de edição para edição.</p>
            </div>

            @if($contests->isNotEmpty())
            <form method="GET" action="{{ route('backend.languages') }}" class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                <label for="contest_id" class="text-sm text-muted-foreground">Competição:</label>
                <select name="contest_id" id="contest_id" class="max-w-full min-w-0 text-sm px-3 py-2 bg-background border border-border rounded-lg">
                    @foreach($contests as $c)
                        <option value="{{ $c->id }}" @selected($contest && $contest->id === $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
                <button class="button-secondary" type="submit">Abrir competição</button>
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
        <div class="p-6 border-b border-border flex items-center justify-between gap-3 flex-wrap">
            <h3 class="font-semibold text-foreground">Linguagens de "{{ $contest->name }}"</h3>
            <button data-dialog-open="addLanguageModal" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary-hover transition-colors">
                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Nova linguagem
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Nome</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Identificador</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Compilação</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Execução</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Submissões</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($languages as $language)
                    <tr>
                        <td class="px-4 py-3 text-sm font-medium text-foreground">{{ $language->name }}</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground font-mono">{{ $language->extension }}</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground font-mono max-w-xs truncate" title="{{ $language->compile_command }}">{{ $language->compile_command }}</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground font-mono max-w-xs truncate" title="{{ $language->run_command }}">{{ $language->run_command }}</td>
                        <td class="px-4 py-3">
                            @if($language->is_active)
                                <span class="px-2 py-1 text-xs font-medium bg-success-soft text-success rounded">Ativa</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium bg-muted text-foreground rounded">Inativa</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $language->runs_count }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <button data-dialog-open="editLanguageModal{{ $language->id }}" class="p-1.5 text-muted-foreground hover:bg-muted rounded transition-colors" title="Editar {{ $language->name }}">
                                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </button>
                                {{-- A language that already has runs cannot be removed at all
                                     (LanguageController::destroy explains why), so the button says
                                     so instead of offering an action that will only be refused. --}}
                                @if($language->runs_count > 0)
                                <span class="p-1.5 text-muted-foreground/50 cursor-not-allowed" title="Já existem submissões nesta linguagem. Desative-a em vez de removê-la." aria-label="Não é possível excluir {{ $language->name }}: já existem submissões">
                                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                    </svg>
                                </span>
                                @else
                                <form action="{{ route('backend.languages.destroy', $language) }}" method="POST" class="inline" data-confirm="Remover esta linguagem? Os limites por problema configurados para ela tambem serao removidos.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="p-1.5 text-destructive hover:bg-destructive-soft rounded transition-colors" title="Excluir {{ $language->name }}">
                                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>

                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">Nenhuma linguagem cadastrada nesta competição ainda</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @foreach($languages as $language)
                    <!-- Modal Editar linguagem -->
                    <div data-dialog id="editLanguageModal{{ $language->id }}" class="fixed inset-0 z-50 hidden">
                        <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" data-dialog-close="editLanguageModal{{ $language->id }}"></div>
                        <div class="fixed inset-0 flex items-center justify-center p-4">
                            <div class="bg-card rounded-xl shadow-xl border border-border w-full max-w-lg max-h-[90vh] overflow-y-auto">
                                <div class="flex flex-wrap items-center justify-between gap-3 p-6 border-b border-border">
                                    <h3 class="text-xl font-semibold text-foreground">Editar {{ $language->name }}</h3>
                                    <button data-dialog-close="editLanguageModal{{ $language->id }}" class="p-2 hover:bg-muted rounded-lg transition-colors">
                                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                                <form action="{{ route('backend.languages.update', $language) }}" method="POST" data-form-key="edit{{ $language->id }}">
                                    @csrf
                                    @method('PUT')
                                    @include('backend.partials.language-fields', ['language' => $language, 'idPrefix' => 'edit' . $language->id])
                                    <div class="flex items-center justify-end gap-3 p-6 border-t border-border bg-muted/30">
                                        <button type="button" data-dialog-close="editLanguageModal{{ $language->id }}" class="px-4 py-2 text-foreground hover:bg-muted rounded-lg transition-colors">Cancelar</button>
                                        <button type="submit" class="px-6 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary-hover transition-colors">Salvar</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
    @endforeach
    @endif
</div>

<!-- Modal Nova linguagem -->
@if($contest)
<div data-dialog id="addLanguageModal" class="fixed inset-0 z-50 hidden">
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" data-dialog-close="addLanguageModal"></div>
    <div class="fixed inset-0 flex items-center justify-center p-4">
        <div class="bg-card rounded-xl shadow-xl border border-border w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div class="flex flex-wrap items-center justify-between gap-3 p-6 border-b border-border">
                <h3 class="text-xl font-semibold text-foreground">Nova linguagem</h3>
                <button data-dialog-close="addLanguageModal" class="p-2 hover:bg-muted rounded-lg transition-colors">
                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <form action="{{ route('backend.languages.store') }}" method="POST" data-form-key="add">
                @csrf
                <input type="hidden" name="contest_id" value="{{ $contest->id }}">
                @include('backend.partials.language-fields', ['language' => null, 'idPrefix' => 'add'])
                <div class="flex items-center justify-end gap-3 p-6 border-t border-border bg-muted/30">
                    <button type="button" data-dialog-close="addLanguageModal" class="px-4 py-2 text-foreground hover:bg-muted rounded-lg transition-colors">Cancelar</button>
                    <button type="submit" class="px-6 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary-hover transition-colors">Cadastrar</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endsection
