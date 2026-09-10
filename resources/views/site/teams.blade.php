@extends('layouts.app')

@section('title', 'Times do Site')

@section('description', 'Cadastre e acompanhe os times vinculados ao seu site.')

@section('content')
<div class="space-y-6">
    {{-- layouts/app.blade.php already renders session('success')/session('error') globally --}}

    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-foreground">Times</h2>
                    <p class="text-sm text-muted-foreground mt-1">{{ $site?->name ?? 'Nenhum site vinculado' }}</p>
                </div>
                @if($site)
                <button onclick="openModal('addTeamModal')" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary-hover transition-colors">
                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Novo time
                </button>
                @endif
            </div>
        </div>
        @include('partials.table-filter', ['tableId' => 'site-teams-table', 'searchLabel' => 'times', 'difficulty' => false])
        <div class="overflow-x-auto">
            <table id="site-teams-table" class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Nome</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Usuario</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">E-mail</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($teams as $team)
                    <tr class="hover:bg-muted/50 transition-colors">
                        <td class="px-4 py-3 text-sm font-medium text-foreground">{{ $team->fullname }}</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $team->username }}</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $team->email ?? '-' }}</td>
                        <td class="px-4 py-3">
                            @if($team->is_enabled ?? true)
                                <span class="px-2 py-1 text-xs font-medium bg-success-soft text-success rounded">Ativo</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium bg-muted text-foreground rounded">Inativo</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-4 py-12 text-center text-muted-foreground">Nenhum time cadastrado neste site ainda</td>
                    </tr>
                    @endforelse
                <tr id="site-teams-table-empty" hidden><td colspan="4" class="text-center text-muted-foreground">Nenhum resultado para estes filtros.</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

@if($site)
<!-- Modal Novo time -->
<div id="addTeamModal" class="fixed inset-0 z-50 hidden">
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('addTeamModal')"></div>
    <div class="fixed inset-0 flex items-center justify-center p-4">
        <div class="bg-card rounded-xl shadow-xl border border-border w-full max-w-lg">
            <div class="flex flex-wrap items-center justify-between gap-3 p-6 border-b border-border">
                <h3 class="text-xl font-semibold text-foreground">Novo time em {{ $site->name }}</h3>
                <button onclick="closeModal('addTeamModal')" class="p-2 hover:bg-muted rounded-lg transition-colors">
                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <form action="{{ route('site.teams.store') }}" method="POST">
                @csrf
                <div class="p-6 space-y-4">
                    <div>
                        <label for="fullname" class="block text-sm font-medium text-foreground mb-1">Nome do time</label>
                        <input id="fullname" type="text" name="fullname" value="{{ old('fullname') }}" maxlength="255" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" required>
                    </div>
                    <div>
                        <label for="username" class="block text-sm font-medium text-foreground mb-1">Nome de usuário</label>
                        <input id="username" type="text" name="username" value="{{ old('username') }}" maxlength="255" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" required>
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-medium text-foreground mb-1">E-mail</label>
                        <input id="email" type="email" name="email" required autocomplete="off" value="{{ old('email') }}" maxlength="255" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>
                    <div>
                        <label for="password" class="block text-sm font-medium text-foreground mb-1">Senha</label>
                        <input id="password" type="password" name="password" minlength="8" autocomplete="new-password" aria-describedby="site-team-password-hint" class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent" required>
                        <p id="site-team-password-hint" class="text-sm text-muted-foreground mt-1">A senha deve ter pelo menos 8 caracteres.</p>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 p-6 border-t border-border bg-muted/30">
                    <button type="button" onclick="closeModal('addTeamModal')" class="px-4 py-2 text-foreground hover:bg-muted rounded-lg transition-colors">Cancelar</button>
                    <button type="submit" class="px-6 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary-hover transition-colors">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endsection

@section('scripts')
<script>
function openModal(id) { document.getElementById(id).classList.remove('hidden'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); document.body.style.overflow = ''; }
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { document.querySelectorAll('[id$="Modal"]').forEach(modal => { if (!modal.classList.contains('hidden')) closeModal(modal.id); }); } });
</script>
@endsection
