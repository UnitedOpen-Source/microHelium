@extends('layouts.app')

@section('title', 'Configuracoes')

@php
    $availableLanguages = \App\Models\Language::getDefaultLanguages();
@endphp

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Main Content -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Hackathons List -->
        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-6 border-b border-border">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-foreground">Configuracoes da Maratona</h2>
                        <p class="text-sm text-muted-foreground mt-1">Gerencie as competicoes e hackathons</p>
                    </div>
                    <a href="{{ route('backend.contest-wizard') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Nova Maratona
                    </a>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Nome</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Descricao</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Inicio</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Fim</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Acoes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($hackathons as $hackathon)
                        <tr class="hover:bg-muted/50 transition-colors">
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ $hackathon->hackathon_id }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-foreground">{{ $hackathon->eventName }}</td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ Str::limit($hackathon->description ?? '-', 50) }}</td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ $hackathon->starts_at ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ $hackathon->ends_at ?? '-' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <form action="/backend/contest/{{ $hackathon->hackathon_id }}/activate" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" class="p-1.5 text-green-600 hover:bg-green-100 dark:hover:bg-green-900/30 rounded transition-colors" title="Ativar">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </button>
                                    </form>
                                    <a href="/backend/contest/{{ $hackathon->hackathon_id }}/edit" class="p-1.5 text-yellow-600 hover:bg-yellow-100 dark:hover:bg-yellow-900/30 rounded transition-colors" title="Editar">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </a>
                                    <form action="/backend/contest/{{ $hackathon->hackathon_id }}/delete" method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir esta maratona?')">
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
                            <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto mb-3 text-muted-foreground/50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                </svg>
                                Nenhuma maratona cadastrada
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="space-y-6">
        <!-- System Config -->
        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-4 border-b border-border">
                <h3 class="font-semibold text-foreground">Configuracoes do Sistema</h3>
            </div>
            <div class="p-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-foreground mb-1">Nome da Competicao</label>
                    <input type="text" class="w-full px-3 py-2 bg-muted border border-border rounded-lg text-foreground" value="{{ config('app.name') }}" disabled>
                </div>
                <div>
                    <label class="block text-sm font-medium text-foreground mb-1">Tempo Limite (segundos)</label>
                    <input type="number" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" value="{{ config('autojudge.time_limit', 10) }}">
                </div>
                <div>
                    <label class="block text-sm font-medium text-foreground mb-1">Memoria Limite (MB)</label>
                    <input type="number" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" value="{{ config('autojudge.memory_limit', 512) }}">
                </div>
                <div>
                    <label class="block text-sm font-medium text-foreground mb-1">Penalidade (minutos)</label>
                    <input type="number" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" value="20">
                </div>
                <button type="button" class="w-full px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors">
                    Salvar Configuracoes
                </button>
            </div>
        </div>

        <!-- Timer Control -->
        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-4 border-b border-border">
                <h3 class="font-semibold text-foreground">Controle do Cronometro</h3>
            </div>
            <div class="p-4 text-center">
                <div class="text-4xl font-mono font-bold text-foreground mb-4" id="timer">00:00:00</div>
                <div class="flex gap-2 justify-center">
                    <button class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                        </svg>
                    </button>
                    <button class="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </button>
                    <button class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-4 border-b border-border">
                <h3 class="font-semibold text-foreground">Acoes Rapidas</h3>
            </div>
            <div class="p-4 space-y-2">
                <a href="/scoreboard/export" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors flex items-center justify-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    Exportar Placar (CSV)
                </a>
                <form action="/backend/contest/freeze" method="POST">
                    @csrf
                    <button type="submit" class="w-full px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-colors flex items-center justify-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707" />
                        </svg>
                        Congelar Placar
                    </button>
                </form>
                <form action="/backend/contest/end" method="POST" onsubmit="return confirm('Tem certeza que deseja encerrar a competicao?')">
                    @csrf
                    <button type="submit" class="w-full px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors flex items-center justify-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
                        </svg>
                        Encerrar Competicao
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal Nova Maratona -->
<div id="addHackathonModal" class="fixed inset-0 z-50 hidden">
    <!-- Backdrop -->
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('addHackathonModal')"></div>

    <!-- Modal Content -->
    <div class="fixed inset-0 flex items-center justify-center p-4">
        <div class="bg-card rounded-xl shadow-xl border border-border w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between p-6 border-b border-border">
                <h3 class="text-xl font-semibold text-foreground">Nova Maratona</h3>
                <button onclick="closeModal('addHackathonModal')" class="p-2 hover:bg-muted rounded-lg transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <form action="/backend/configurations" method="POST">
                @csrf
                <div class="p-6 space-y-6">
                    <!-- Basic Info -->
                    <div class="space-y-4">
                        <h4 class="text-sm font-medium text-muted-foreground uppercase tracking-wider">Informacoes Basicas</h4>

                        <div>
                            <label class="block text-sm font-medium text-foreground mb-1">Nome da Maratona *</label>
                            <input type="text" name="eventName" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Ex: Maratona de Programacao 2025" required>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-foreground mb-1">Descricao</label>
                            <textarea name="description" rows="3" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent resize-none" placeholder="Descricao da competicao"></textarea>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-foreground mb-1">Data/Hora de Inicio</label>
                                <input type="datetime-local" name="starts_at" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-foreground mb-1">Data/Hora de Termino</label>
                                <input type="datetime-local" name="ends_at" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                        </div>
                    </div>

                    <!-- Languages Selection -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-medium text-muted-foreground uppercase tracking-wider">Linguagens Permitidas</h4>
                            <div class="flex gap-2">
                                <button type="button" onclick="selectAllLanguages()" class="text-xs px-2 py-1 bg-primary/10 text-primary rounded hover:bg-primary/20 transition-colors">
                                    Selecionar Todas
                                </button>
                                <button type="button" onclick="deselectAllLanguages()" class="text-xs px-2 py-1 bg-muted text-muted-foreground rounded hover:bg-muted/80 transition-colors">
                                    Limpar
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            @foreach($availableLanguages as $index => $lang)
                            <label class="language-checkbox flex items-center gap-3 p-3 bg-muted/50 border border-border rounded-lg cursor-pointer hover:bg-muted transition-colors">
                                <input type="checkbox" name="languages[]" value="{{ $lang['extension'] }}" class="w-4 h-4 rounded border-border text-primary focus:ring-primary" checked>
                                <div class="flex-1 min-w-0">
                                    <span class="block text-sm font-medium text-foreground">{{ $lang['name'] }}</span>
                                    <span class="block text-xs text-muted-foreground">.{{ $lang['extension'] }}</span>
                                </div>
                            </label>
                            @endforeach
                        </div>

                        <p class="text-xs text-muted-foreground">
                            Selecione as linguagens que os participantes poderao usar para submeter solucoes nesta maratona.
                        </p>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 p-6 border-t border-border bg-muted/30">
                    <button type="button" onclick="closeModal('addHackathonModal')" class="px-4 py-2 text-foreground hover:bg-muted rounded-lg transition-colors">
                        Cancelar
                    </button>
                    <button type="submit" class="px-6 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors">
                        Criar Maratona
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id).classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
    document.body.style.overflow = '';
}

function selectAllLanguages() {
    document.querySelectorAll('.language-checkbox input[type="checkbox"]').forEach(cb => cb.checked = true);
}

function deselectAllLanguages() {
    document.querySelectorAll('.language-checkbox input[type="checkbox"]').forEach(cb => cb.checked = false);
}

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('[id$="Modal"]').forEach(modal => {
            if (!modal.classList.contains('hidden')) {
                closeModal(modal.id);
            }
        });
    }
});
</script>
@endsection
