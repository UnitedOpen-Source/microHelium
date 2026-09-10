@extends('layouts.app')

@section('title', 'Gerenciar problemas')

@section('description', 'Organize os desafios disponíveis para os participantes.')

@section('content')
<div class="space-y-6">
    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-foreground">Gerenciar problemas</h2>
                    <p class="text-sm text-muted-foreground mt-1">Lista de todos os exercicios/problemas da competicao</p>
                </div>
                <button onclick="openModal('addExerciseModal')" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Novo problema
                </button>
            </div>
        </div>

        @include('partials.table-filter', ['tableId' => 'backend-exercises-table', 'searchLabel' => 'problemas', 'difficulty' => false])
        <div class="overflow-x-auto">
            <table id="backend-exercises-table" class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Nome</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Categoria</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Dificuldade</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Pontuação</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Criado em</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Acoes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($exercises as $exercise)
                    <tr class="hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $exercise->exercise_id }}</td>
                        <td class="px-4 py-3 text-sm font-medium text-foreground">{{ $exercise->exerciseName }}</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $exercise->category ?? '-' }}</td>
                        <td class="px-4 py-3">
                            @if(($exercise->difficulty ?? 'medium') == 'easy')
                                <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 rounded">Fácil</span>
                            @elseif(($exercise->difficulty ?? 'medium') == 'medium')
                                <span class="px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400 rounded">Médio</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 rounded">Difícil</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-foreground">{{ $exercise->score ?? 100 }} pts</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $exercise->created_at }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <form action="/backend/exercises/{{ $exercise->exercise_id }}" method="POST" class="inline" onsubmit="return confirm('Excluir este exercicio?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="p-1.5 text-red-600 hover:bg-red-100 dark:hover:bg-red-900/30 rounded transition-colors" title="Excluir">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-muted-foreground">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto mb-3 text-muted-foreground/50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            Nenhum exercicio cadastrado
                        </td>
                    </tr>
                    @endforelse
                <tr id="backend-exercises-table-empty" hidden><td colspan="7" class="text-center text-muted-foreground">Nenhum resultado para estes filtros. Tente outro termo ou limpe a busca.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Novo problema -->
<div id="addExerciseModal" class="fixed inset-0 z-50 hidden">
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('addExerciseModal')"></div>
    <div class="fixed inset-0 flex items-center justify-center p-4">
        <div class="bg-card rounded-xl shadow-xl border border-border w-full max-w-lg">
            <div class="flex flex-wrap items-center justify-between gap-3 p-6 border-b border-border">
                <h3 class="text-xl font-semibold text-foreground">Novo problema</h3>
                <button onclick="closeModal('addExerciseModal')" class="p-2 hover:bg-muted rounded-lg transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <form action="/backend/exercises" method="POST">
                @csrf
                <div class="p-6 space-y-4">
                    <div>
                        <label for="exerciseName" class="block text-sm font-medium text-foreground mb-1">Nome do problema</label>
                        <input id="exerciseName" type="text" name="exerciseName" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Ex: Soma de Dois Numeros" required>
                    </div>
                    <div>
                        <label for="category" class="block text-sm font-medium text-foreground mb-1">Categoria</label>
                        <input id="category" type="text" name="category" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Ex: Matematica, Strings, Grafos">
                    </div>
                    <div>
                        <label for="difficulty" class="block text-sm font-medium text-foreground mb-1">Dificuldade</label>
                        <select id="difficulty" name="difficulty" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
                            <option value="easy">Fácil</option>
                            <option value="medium" selected>Médio</option>
                            <option value="hard">Difícil</option>
                        </select>
                    </div>
                    <div>
                        <label for="score" class="block text-sm font-medium text-foreground mb-1">Pontuação</label>
                        <input id="score" type="number" name="score" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" value="100">
                    </div>
                    <div>
                        <label for="expectedOutcome" class="block text-sm font-medium text-foreground mb-1">Resultado Esperado</label>
                        <textarea id="expectedOutcome" name="expectedOutcome" rows="3" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent resize-none" placeholder="Descreva a saida esperada"></textarea>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 p-6 border-t border-border bg-muted/30">
                    <button type="button" onclick="closeModal('addExerciseModal')" class="px-4 py-2 text-foreground hover:bg-muted rounded-lg transition-colors">Cancelar</button>
                    <button type="submit" class="px-6 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@section('scripts')
<script>
function openModal(id) {
    document.getElementById(id).classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('[id$="Modal"]').forEach(modal => {
            if (!modal.classList.contains('hidden')) closeModal(modal.id);
        });
    }
});
</script>
@endsection
