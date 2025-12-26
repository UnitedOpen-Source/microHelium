@extends('layouts.app')

@section('title', 'Banco de Problemas')

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-foreground">Banco de Problemas</h1>
            <p class="text-sm text-muted-foreground mt-1">Gerencie os problemas disponiveis para suas maratonas</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('backend.import-boca') }}" class="px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                </svg>
                Importar BOCA
            </a>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="bg-card border border-border rounded-lg p-4">
            <p class="text-sm text-muted-foreground">Total</p>
            <p class="text-2xl font-bold text-foreground">{{ $problems->count() }}</p>
        </div>
        <div class="bg-card border border-border rounded-lg p-4">
            <p class="text-sm text-muted-foreground">Facil</p>
            <p class="text-2xl font-bold text-green-600">{{ $problems->where('difficulty', 'easy')->count() }}</p>
        </div>
        <div class="bg-card border border-border rounded-lg p-4">
            <p class="text-sm text-muted-foreground">Medio</p>
            <p class="text-2xl font-bold text-yellow-600">{{ $problems->where('difficulty', 'medium')->count() }}</p>
        </div>
        <div class="bg-card border border-border rounded-lg p-4">
            <p class="text-sm text-muted-foreground">Dificil</p>
            <p class="text-2xl font-bold text-red-600">{{ $problems->where('difficulty', 'hard')->count() }}</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-card border border-border rounded-lg p-4">
        <div class="flex flex-wrap gap-2 items-center">
            <span class="text-sm font-medium text-foreground">Filtrar:</span>
            <button onclick="filterProblems('all')" class="filter-btn px-3 py-1 bg-primary text-primary-foreground rounded text-sm" data-filter="all">Todos</button>
            <button onclick="filterProblems('easy')" class="filter-btn px-3 py-1 bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400 rounded text-sm" data-filter="easy">Facil</button>
            <button onclick="filterProblems('medium')" class="filter-btn px-3 py-1 bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400 rounded text-sm" data-filter="medium">Medio</button>
            <button onclick="filterProblems('hard')" class="filter-btn px-3 py-1 bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400 rounded text-sm" data-filter="hard">Dificil</button>
            <div class="flex-1"></div>
            <input type="text" id="searchInput" placeholder="Buscar problema..."
                class="px-3 py-1 bg-background border border-border rounded text-sm text-foreground w-48"
                oninput="searchProblems(this.value)">
        </div>
    </div>

    <!-- Problem List -->
    <div class="bg-card border border-border rounded-lg overflow-hidden">
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
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Acoes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($problems as $problem)
                    <tr class="problem-row hover:bg-muted/30" data-difficulty="{{ $problem->difficulty }}" data-name="{{ strtolower($problem->name) }}" data-code="{{ strtolower($problem->code) }}">
                        <td class="px-4 py-3">
                            <span class="font-mono font-bold text-primary bg-primary/10 px-2 py-1 rounded text-xs">{{ $problem->code }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-foreground">{{ $problem->name }}</div>
                            <div class="text-xs text-muted-foreground truncate max-w-xs">{{ Str::limit($problem->description, 60) }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $badges = [
                                    'easy' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
                                    'medium' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
                                    'hard' => 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
                                ];
                                $labels = ['easy' => 'Facil', 'medium' => 'Medio', 'hard' => 'Dificil'];
                            @endphp
                            <span class="px-2 py-1 rounded text-xs {{ $badges[$problem->difficulty] ?? 'bg-gray-100' }}">
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
                                <span class="px-2 py-1 bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400 rounded text-xs">Ativo</span>
                            @else
                                <span class="px-2 py-1 bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400 rounded text-xs">Inativo</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex gap-1">
                                <button onclick="viewProblem({{ $problem->id }})" class="p-1.5 text-muted-foreground hover:text-primary rounded" title="Ver detalhes">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>
                                <form method="POST" action="/backend/problem-bank/{{ $problem->id }}/toggle" class="inline">
                                    @csrf
                                    <button type="submit" class="p-1.5 text-muted-foreground hover:text-yellow-600 rounded" title="Alternar status">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                                        </svg>
                                    </button>
                                </form>
                                <form method="POST" action="/backend/problem-bank/{{ $problem->id }}" class="inline" onsubmit="return confirm('Tem certeza que deseja remover este problema?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="p-1.5 text-muted-foreground hover:text-red-600 rounded" title="Remover">
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
                        <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">
                            <div class="flex flex-col items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-muted-foreground/50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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
        <div class="p-6 border-b border-border flex items-center justify-between">
            <h3 id="modalTitle" class="text-xl font-semibold text-foreground">Detalhes do Problema</h3>
            <button onclick="closeModal()" class="p-2 hover:bg-muted rounded">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div id="modalContent" class="p-6">
            <!-- Content loaded via JS -->
        </div>
    </div>
</div>

<script>
const problems = @json($problems);

function filterProblems(difficulty) {
    document.querySelectorAll('.problem-row').forEach(row => {
        if (difficulty === 'all' || row.dataset.difficulty === difficulty) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function searchProblems(query) {
    query = query.toLowerCase();
    document.querySelectorAll('.problem-row').forEach(row => {
        const name = row.dataset.name;
        const code = row.dataset.code;
        if (name.includes(query) || code.includes(query)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function viewProblem(id) {
    const problem = problems.find(p => p.id === id);
    if (!problem) return;

    document.getElementById('modalTitle').textContent = problem.code + ' - ' + problem.name;
    document.getElementById('modalContent').innerHTML = `
        <div class="space-y-4">
            <div>
                <h4 class="font-semibold text-foreground mb-2">Descricao</h4>
                <div class="text-sm text-muted-foreground whitespace-pre-wrap bg-muted/50 p-4 rounded">${escapeHtml(problem.description)}</div>
            </div>
            <div class="grid grid-cols-2 gap-4">
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
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <h4 class="font-semibold text-foreground mb-2">Exemplo de Entrada</h4>
                    <pre class="text-sm bg-muted p-4 rounded overflow-x-auto">${escapeHtml(problem.sample_input)}</pre>
                </div>
                <div>
                    <h4 class="font-semibold text-foreground mb-2">Exemplo de Saida</h4>
                    <pre class="text-sm bg-muted p-4 rounded overflow-x-auto">${escapeHtml(problem.sample_output)}</pre>
                </div>
            </div>
            ` : ''}
            <div class="flex gap-4 text-sm text-muted-foreground">
                <span>Tempo: ${problem.time_limit}s</span>
                <span>Memoria: ${problem.memory_limit}MB</span>
                <span>Fonte: ${problem.source}</span>
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
