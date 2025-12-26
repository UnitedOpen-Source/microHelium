@extends('layouts.app')

@section('title', 'Gerenciar Usuarios')

@section('content')
<div class="space-y-6">
    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-foreground">Gerenciar Usuarios</h2>
                    <p class="text-sm text-muted-foreground mt-1">Lista de todos os usuarios do sistema</p>
                </div>
                <button onclick="openModal('addUserModal')" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Novo Usuario
                </button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Nome Completo</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Username</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Email</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Tipo</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Criado em</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Acoes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($users as $user)
                    <tr class="hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $user->user_id }}</td>
                        <td class="px-4 py-3 text-sm font-medium text-foreground">{{ $user->fullname }}</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $user->username }}</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $user->email ?? '-' }}</td>
                        <td class="px-4 py-3">
                            @if(($user->user_type ?? 'team') == 'admin')
                                <span class="px-2 py-1 text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 rounded">Admin</span>
                            @elseif(($user->user_type ?? 'team') == 'judge')
                                <span class="px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400 rounded">Juiz</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400 rounded">Competidor</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($user->is_enabled ?? true)
                                <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 rounded">Ativo</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400 rounded">Inativo</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $user->created_at }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <button class="p-1.5 text-yellow-600 hover:bg-yellow-100 dark:hover:bg-yellow-900/30 rounded transition-colors" title="Editar">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </button>
                                <form action="/backend/users/{{ $user->user_id }}" method="POST" class="inline" onsubmit="return confirm('Excluir este usuario?')">
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
                        <td colspan="8" class="px-4 py-12 text-center text-muted-foreground">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto mb-3 text-muted-foreground/50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z" />
                            </svg>
                            Nenhum usuario cadastrado
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Novo Usuario -->
<div id="addUserModal" class="fixed inset-0 z-50 hidden">
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('addUserModal')"></div>
    <div class="fixed inset-0 flex items-center justify-center p-4">
        <div class="bg-card rounded-xl shadow-xl border border-border w-full max-w-lg">
            <div class="flex items-center justify-between p-6 border-b border-border">
                <h3 class="text-xl font-semibold text-foreground">Novo Usuario</h3>
                <button onclick="closeModal('addUserModal')" class="p-2 hover:bg-muted rounded-lg transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <form action="/backend/users" method="POST">
                @csrf
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-1">Nome Completo</label>
                        <input type="text" name="fullname" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Nome completo" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-1">Username</label>
                        <input type="text" name="username" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="nome.usuario" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-1">Email</label>
                        <input type="email" name="email" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="email@exemplo.com">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-1">Senha</label>
                        <input type="password" name="password" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Senha" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-1">Tipo de Usuario</label>
                        <select name="user_type" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
                            <option value="team">Competidor</option>
                            <option value="judge">Juiz</option>
                            <option value="admin">Administrador</option>
                        </select>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 p-6 border-t border-border bg-muted/30">
                    <button type="button" onclick="closeModal('addUserModal')" class="px-4 py-2 text-foreground hover:bg-muted rounded-lg transition-colors">Cancelar</button>
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
