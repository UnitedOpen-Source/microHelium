@extends('layouts.app')

@section('title', 'Gerenciar usuários')

@section('description', 'Gerencie participantes, credenciais e perfis de acesso.')

@section('content')
<div class="space-y-6">
    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-foreground">Contas cadastradas</h2>
                    <p class="text-sm text-muted-foreground mt-1">Lista de todos os usuarios do sistema</p>
                </div>
                <button onclick="openModal('addUserModal')" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary-hover transition-colors">
                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Novo usuário
                </button>
            </div>
        </div>

        @include('partials.table-filter', ['tableId' => 'backend-users-table', 'searchLabel' => 'usuários', 'difficulty' => false])
        <div class="overflow-x-auto">
            <table id="backend-users-table" class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Nome Completo</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Nome de usuário</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">E-mail</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Tipo</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Criado em</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Ações</th>
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
                                <span class="px-2 py-1 text-xs font-medium bg-info-soft text-info rounded">Admin</span>
                            @elseif(($user->user_type ?? 'team') == 'judge')
                                <span class="px-2 py-1 text-xs font-medium bg-muted text-foreground rounded">Juiz</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium bg-info-soft text-info rounded">Competidor</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($user->is_enabled ?? true)
                                <span class="px-2 py-1 text-xs font-medium bg-success-soft text-success rounded">Ativo</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium bg-muted text-foreground rounded">Inativo</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $user->created_at }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <form action="/backend/users/{{ $user->user_id }}" method="POST" class="inline" onsubmit="return confirm('Excluir este usuario?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="p-1.5 text-destructive hover:bg-destructive-soft rounded transition-colors" title="Excluir {{ $user->fullname }}">
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
                        <td colspan="8" class="px-4 py-12 text-center text-muted-foreground">
                            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto mb-3 text-muted-foreground/50" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z" />
                            </svg>
                            Nenhum usuario cadastrado
                        </td>
                    </tr>
                    @endforelse
                <tr id="backend-users-table-empty" hidden><td colspan="8" class="text-center text-muted-foreground">Nenhum resultado para estes filtros. Tente outro termo ou limpe a busca.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Novo usuário -->
<div id="addUserModal" class="fixed inset-0 z-50 hidden">
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('addUserModal')"></div>
    <div class="fixed inset-0 flex items-center justify-center p-4">
        <div class="bg-card rounded-xl shadow-xl border border-border w-full max-w-lg">
            <div class="flex flex-wrap items-center justify-between gap-3 p-6 border-b border-border">
                <h3 class="text-xl font-semibold text-foreground">Novo usuário</h3>
                <button onclick="closeModal('addUserModal')" class="p-2 hover:bg-muted rounded-lg transition-colors">
                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <form action="/backend/users" method="POST">
                @csrf
                <div class="p-6 space-y-4">
                    <div>
                        <label for="fullname" class="block text-sm font-medium text-foreground mb-1">Nome Completo</label>
                        <input id="fullname" type="text" name="fullname" value="{{ old('fullname') }}" maxlength="255" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Nome completo" required>
                    </div>
                    <div>
                        <label for="username" class="block text-sm font-medium text-foreground mb-1">Nome de usuário</label>
                        <input id="username" type="text" name="username" value="{{ old('username') }}" maxlength="255" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="nome.usuario" required>
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-medium text-foreground mb-1">E-mail</label>
                        <input id="email" type="email" name="email" required autocomplete="off" value="{{ old('email') }}" maxlength="255" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="email@exemplo.com">
                    </div>
                    <div>
                        <label for="password" class="block text-sm font-medium text-foreground mb-1">Senha</label>
                        <input id="password" type="password" name="password" minlength="8" autocomplete="new-password" aria-describedby="admin-password-hint" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Senha" required>
                    </div>
                    <div>
                        <p id="admin-password-hint" class="text-sm text-muted-foreground mb-3">A senha deve ter pelo menos 8 caracteres.</p>
                        <label for="user_type" class="block text-sm font-medium text-foreground mb-1">Perfil de acesso</label>
                        <select id="user_type" name="user_type" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
                            <option value="team" @selected(old('user_type', 'team') === 'team')>Competidor</option>
                            <option value="judge" @selected(old('user_type', 'team') === 'judge')>Juiz</option>
                            <option value="admin" @selected(old('user_type', 'team') === 'admin')>Administrador</option>
                        </select>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 p-6 border-t border-border bg-muted/30">
                    <button type="button" onclick="closeModal('addUserModal')" class="px-4 py-2 text-foreground hover:bg-muted rounded-lg transition-colors">Cancelar</button>
                    <button type="submit" class="px-6 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary-hover transition-colors">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@section('scripts')
<script>
function openModal(id) { document.getElementById(id).classList.remove('hidden'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); document.body.style.overflow = ''; }
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { document.querySelectorAll('[id$="Modal"]').forEach(modal => { if (!modal.classList.contains('hidden')) closeModal(modal.id); }); } });
</script>
@endsection
