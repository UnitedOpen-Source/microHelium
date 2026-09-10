@extends('layouts.app')

@section('title', 'Banco de Problemas')

@section('description', 'Encontre, revise e organize os problemas do seu acervo.')

@section('page-actions')<a href="{{ route('backend.import-boca') }}" class="button-primary">Importar BOCA</a>@endsection

@section('content')
<div class="space-y-6">
    <!-- Stats -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="bg-card border border-border rounded-lg p-4">
            <p class="text-sm text-muted-foreground">Total</p>
            <p class="text-2xl font-bold text-foreground">{{ $problems->count() }}</p>
        </div>
        <div class="bg-card border border-border rounded-lg p-4">
            <p class="text-sm text-muted-foreground">Fácil</p>
            <p class="text-2xl font-bold text-success">{{ $problems->where('difficulty', 'easy')->count() }}</p>
        </div>
        <div class="bg-card border border-border rounded-lg p-4">
            <p class="text-sm text-muted-foreground">Médio</p>
            <p class="text-2xl font-bold text-warning">{{ $problems->where('difficulty', 'medium')->count() }}</p>
        </div>
        <div class="bg-card border border-border rounded-lg p-4">
            <p class="text-sm text-muted-foreground">Difícil</p>
            <p class="text-2xl font-bold text-destructive">{{ $problems->where('difficulty', 'hard')->count() }}</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-card border border-border rounded-lg p-4">
        <div class="flex flex-wrap gap-2 items-center">
            <span class="text-sm font-medium text-foreground">Filtrar:</span>
            <button onclick="filterProblems('all')" class="filter-btn px-3 py-1 bg-primary text-primary-foreground rounded text-sm" data-filter="all">Todos</button>
            <button onclick="filterProblems('easy')" class="filter-btn px-3 py-1 bg-success-soft text-success rounded text-sm" data-filter="easy">Fácil</button>
            <button onclick="filterProblems('medium')" class="filter-btn px-3 py-1 bg-warning-soft text-warning rounded text-sm" data-filter="medium">Médio</button>
            <button onclick="filterProblems('hard')" class="filter-btn px-3 py-1 bg-destructive-soft text-destructive rounded text-sm" data-filter="hard">Difícil</button>
            <div class="flex-1"></div>
            <input type="search" id="searchInput" aria-label="Buscar problemas" placeholder="Buscar problema…"
                class="px-3 py-1 bg-background border border-border rounded text-sm text-foreground w-full sm:w-64"
                oninput="searchProblems(this.value)">
        </div>
    </div>

    <!-- Problem List -->
    <div class="bg-card border border-border rounded-lg overflow-hidden">
        <p id="bank-filter-status" role="status" class="px-6 py-3 text-sm text-muted-foreground"></p>
    <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Codigo</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Nome</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Dificuldade</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Fonte</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Limites</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Status</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($problems as $problem)
                    <tr class="problem-row hover:bg-muted/30" data-difficulty="{{ $problem->difficulty }}" data-name="{{ strtolower($problem->name) }}" data-code="{{ strtolower($problem->code) }}">
                        <td class="px-4 py-3">
                            <span class="font-mono font-bold text-primary bg-primary-soft px-2 py-1 rounded text-xs">{{ $problem->code }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-foreground">{{ $problem->name }}</div>
                            <div class="text-xs text-muted-foreground truncate max-w-xs">{{ Str::limit($problem->description, 60) }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $badges = [
                                    'easy' => 'bg-success-soft text-success  ',
                                    'medium' => 'bg-warning-soft text-warning  ',
                                    'hard' => 'bg-destructive-soft text-destructive  ',
                                ];
                                $labels = ['easy' => 'Fácil', 'medium' => 'Médio', 'hard' => 'Difícil'];
                            @endphp
                            <span class="px-2 py-1 rounded text-xs {{ $badges[$problem->difficulty] ?? 'bg-muted' }}">
                                {{ $labels[$problem->difficulty] ?? 'Desconhecido' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            @if($problem->source_url)
                                <a href="{{ $problem->source_url }}" target="_blank" class="text-primary hover:underline">{{ $problem->source }}</a>
                            @else
                                {{ $problem->source }}
                            @endif
                        </td>
                        <td class="px-4 py-3 text-muted-foreground text-xs">
                            <div>{{ $problem->time_limit }}s / {{ $problem->memory_limit }}MB</div>
                        </td>
                        <td class="px-4 py-3">
                            @if($problem->is_active)
                                <span class="px-2 py-1 bg-success-soft text-success rounded text-xs">Ativo</span>
                            @else
                                <span class="px-2 py-1 bg-muted text-muted-foreground rounded text-xs">Inativo</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex gap-1">
                                <button onclick="viewProblem({{ $problem->id }})" class="p-1.5 text-muted-foreground hover:text-primary rounded" title="Ver detalhes">
                                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>
                                <form method="POST" action="/backend/problem-bank/{{ $problem->id }}/toggle" class="inline">
                                    @csrf
                                    <button type="submit" class="p-1.5 text-muted-foreground hover:text-warning rounded" title="Alternar status">
                                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                                        </svg>
                                    </button>
                                </form>
                                <form method="POST" action="/backend/problem-bank/{{ $problem->id }}" class="inline" onsubmit="return confirm('Tem certeza que deseja remover este problema?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="p-1.5 text-muted-foreground hover:text-destructive rounded" title="Remover">
                                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">
                            <div class="flex flex-col items-center gap-2">
                                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-muted-foreground/50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <p>Nenhum problema no banco</p>
                                <a href="{{ route('backend.import-boca') }}" class="text-primary hover:underline">Importar problemas do BOCA</a>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Problem Detail Modal -->
<div id="problemModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
    <div class="bg-card rounded-lg border border-border max-w-3xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-border flex flex-wrap items-center justify-between gap-3">
            <h3 id="modalTitle" class="text-xl font-semibold text-foreground">Detalhes do Problema</h3>
            <button onclick="closeModal()" class="p-2 hover:bg-muted rounded">
                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div id="modalContent" class="p-6">
            <!-- Content loaded via JS -->
        </div>
    </div>
</div>

@endsection

@section('scripts')
<script>
const problems = @json($problems);

const bankParams = new URL(location.href).searchParams;
let activeDifficulty = bankParams.get('ui-bank-level') || 'all';
let activeQuery = bankParams.get('ui-bank-query') || '';
document.getElementById('searchInput').value = activeQuery;
function applyProblemFilters(save = false) {
    if (!['all', 'easy', 'medium', 'hard'].includes(activeDifficulty)) activeDifficulty = 'all';
    if (save) {
        const url = new URL(location.href);
        for (const [name, value] of [['ui-bank-level', activeDifficulty === 'all' ? '' : activeDifficulty], ['ui-bank-query', activeQuery]]) {
            if (value) url.searchParams.set(name, value); else url.searchParams.delete(name);
        }
        history.replaceState(history.state, '', url);
    }
    const normalize = text => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    let count = 0;
    document.querySelectorAll('.problem-row').forEach(row => {
        const match = (activeDifficulty === 'all' || row.dataset.difficulty === activeDifficulty) && normalize(row.dataset.name + ' ' + row.dataset.code).includes(normalize(activeQuery));
        row.hidden = !match;
        if (match) count++;
    });
    document.querySelectorAll('.filter-btn').forEach(button => {
        button.setAttribute('aria-pressed', String(button.dataset.filter === activeDifficulty));
    });
    document.getElementById('bank-filter-status').textContent = count ? `${count} problemas encontrados` : 'Nenhum problema encontrado. Altere a busca ou a dificuldade.';
}
function filterProblems(difficulty) { activeDifficulty = difficulty; applyProblemFilters(true); }
function searchProblems(query) { activeQuery = query; applyProblemFilters(true); }

applyProblemFilters();
for (const event of ['pageshow', 'popstate']) window.addEventListener(event, () => {
    const params = new URL(location.href).searchParams;
    activeDifficulty = params.get('ui-bank-level') || 'all';
    activeQuery = params.get('ui-bank-query') || '';
    document.getElementById('searchInput').value = activeQuery;
    applyProblemFilters();
});

function viewProblem(id) {
    const problem = problems.find(p => p.id === id);
    if (!problem) return;

    document.getElementById('modalTitle').textContent = problem.code + ' - ' + problem.name;
    document.getElementById('modalContent').innerHTML = `
        <div class="space-y-4">
            <div>
                <h4 class="font-semibold text-foreground mb-2">Descrição</h4>
                <div class="text-sm text-muted-foreground whitespace-pre-wrap bg-muted/50 p-4 rounded">${escapeHtml(problem.description)}</div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <h4 class="font-semibold text-foreground mb-2">Entrada</h4>
                    <div class="text-sm text-muted-foreground bg-muted/50 p-4 rounded">${escapeHtml(problem.input_description)}</div>
                </div>
                <div>
                    <h4 class="font-semibold text-foreground mb-2">Saida</h4>
                    <div class="text-sm text-muted-foreground bg-muted/50 p-4 rounded">${escapeHtml(problem.output_description)}</div>
                </div>
            </div>
            ${problem.sample_input ? `
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <h4 class="font-semibold text-foreground mb-2">Exemplo de Entrada</h4>
                    <pre tabindex="0" translate="no" class="text-sm bg-muted p-4 rounded overflow-x-auto">${escapeHtml(problem.sample_input)}</pre>
                </div>
                <div>
                    <h4 class="font-semibold text-foreground mb-2">Exemplo de Saida</h4>
                    <pre tabindex="0" translate="no" class="text-sm bg-muted p-4 rounded overflow-x-auto">${escapeHtml(problem.sample_output)}</pre>
                </div>
            </div>
            ` : ''}
            <div class="flex gap-4 text-sm text-muted-foreground">
                <span>Tempo: ${problem.time_limit}s</span>
                <span>Memória: ${problem.memory_limit}MB</span>
                <span>Fonte: ${escapeHtml(problem.source)}</span>
            </div>
        </div>
    `;
    document.getElementById('problemModal').classList.remove('hidden');
    document.getElementById('problemModal').classList.add('flex');
}

function closeModal() {
    document.getElementById('problemModal').classList.add('hidden');
    document.getElementById('problemModal').classList.remove('flex');
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.getElementById('problemModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
@endsection
