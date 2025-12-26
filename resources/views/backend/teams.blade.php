@extends('layouts.app')

@section('title', 'Gerenciar Times')

@section('content')
<div class="space-y-6">
    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-foreground">Gerenciar Times</h2>
                    <p class="text-sm text-muted-foreground mt-1">Lista de todos os times da competicao</p>
                </div>
                <button onclick="openModal('addTeamModal')" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Novo Time
                </button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Nome do Time</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Email</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Pontuacao</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Problemas</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Criado em</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Acoes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($teams as $team)
                    <tr class="hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $team->team_id }}</td>
                        <td class="px-4 py-3 text-sm font-medium text-foreground">{{ $team->teamName }}</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $team->email ?? '-' }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 text-xs font-medium bg-primary/10 text-primary rounded">{{ $team->score ?? 0 }} pts</span>
                        </td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $team->problems_solved ?? 0 }}</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $team->created_at }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <button class="p-1.5 text-blue-600 hover:bg-blue-100 dark:hover:bg-blue-900/30 rounded transition-colors" title="Ver">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>
                                <button class="p-1.5 text-yellow-600 hover:bg-yellow-100 dark:hover:bg-yellow-900/30 rounded transition-colors" title="Editar">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </button>
                                <form action="/backend/teams/{{ $team->team_id }}" method="POST" class="inline" onsubmit="return confirm('Excluir este time?')">
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
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                            Nenhum time cadastrado
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-card rounded-lg border border-border shadow-sm p-6 text-center">
            <p class="text-sm text-muted-foreground">Total de Times</p>
            <p class="text-3xl font-bold text-foreground mt-2">{{ count($teams) }}</p>
        </div>
        <div class="bg-card rounded-lg border border-border shadow-sm p-6 text-center">
            <p class="text-sm text-muted-foreground">Maior Pontuacao</p>
            <p class="text-3xl font-bold text-green-600 mt-2">{{ $teams->max('score') ?? 0 }} pts</p>
        </div>
        <div class="bg-card rounded-lg border border-border shadow-sm p-6 text-center">
            <p class="text-sm text-muted-foreground">Media de Pontos</p>
            <p class="text-3xl font-bold text-primary mt-2">{{ number_format($teams->avg('score') ?? 0, 1) }} pts</p>
        </div>
    </div>
</div>

<!-- Modal Novo Time -->
<div id="addTeamModal" class="fixed inset-0 z-50 hidden">
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('addTeamModal')"></div>
    <div class="fixed inset-0 flex items-center justify-center p-4">
        <div class="bg-card rounded-xl shadow-xl border border-border w-full max-w-lg">
            <div class="flex items-center justify-between p-6 border-b border-border">
                <h3 class="text-xl font-semibold text-foreground">Novo Time</h3>
                <button onclick="closeModal('addTeamModal')" class="p-2 hover:bg-muted rounded-lg transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <form action="/backend/teams" method="POST">
                @csrf
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-1">Nome do Time</label>
                        <input type="text" name="teamName" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Ex: Os Programadores" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-1">Email de Contato</label>
                        <input type="email" name="email" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="time@exemplo.com">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-1">Pontuacao Inicial</label>
                        <input type="number" name="score" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" value="0">
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 p-6 border-t border-border bg-muted/30">
                    <button type="button" onclick="closeModal('addTeamModal')" class="px-4 py-2 text-foreground hover:bg-muted rounded-lg transition-colors">Cancelar</button>
                    <button type="submit" class="px-6 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.remove('hidden'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); document.body.style.overflow = ''; }
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { document.querySelectorAll('[id$="Modal"]').forEach(modal => { if (!modal.classList.contains('hidden')) closeModal(modal.id); }); } });
</script>
@endsection
