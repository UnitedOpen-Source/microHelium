@extends('layouts.app')

@section('title', 'Gerenciar Sites')

@section('description', 'Configure os sites (locais físicos) desta competição, roteamento de julgamento e visibilidade do placar.')

@section('content')
<div class="space-y-6">
    {{-- layouts/app.blade.php already renders session('success')/session('error') globally --}}

    <div class="bg-card rounded-lg border border-border shadow-sm p-6">
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h2 class="text-xl font-semibold text-foreground">Sites</h2>
                <p class="text-sm text-muted-foreground">Cada site representa um local físico da competição. Times, juízes e staff podem ser vinculados a um site.</p>
            </div>

            @if($contests->isNotEmpty())
            <form method="GET" action="{{ route('backend.sites') }}" class="flex items-center gap-2">
                <label for="contest_id" class="text-sm text-muted-foreground">Competição:</label>
                <select name="contest_id" id="contest_id" onchange="this.form.submit()" class="text-sm px-3 py-2 bg-background border border-border rounded-lg">
                    @foreach($contests as $c)
                        <option value="{{ $c->id }}" @selected($contest && $contest->id === $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
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
            <h3 class="font-semibold text-foreground">Sites de "{{ $contest->name }}"</h3>
            <button onclick="openModal('addSiteModal')" class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary-hover transition-colors">
                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Novo site
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-muted/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Nome</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Placar</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Espera max. julgamento</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Também julga</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($sites as $site)
                    <tr>
                        <td class="px-4 py-3 text-sm font-medium text-foreground">{{ $site->name }}</td>
                        <td class="px-4 py-3">
                            @if($site->is_active)
                                <span class="px-2 py-1 text-xs font-medium bg-success-soft text-success rounded">Ativo</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium bg-muted text-foreground rounded">Inativo</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ $site->score_visibility === 'own_site' ? 'Apenas este site' : 'Todos os sites' }}</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">{{ intdiv($site->max_judge_wait_time, 60) }} min</td>
                        <td class="px-4 py-3 text-sm text-muted-foreground">
                            @php $routedNames = $sites->whereIn('id', $site->judgingRoutes->pluck('source_site_id'))->pluck('name'); @endphp
                            {{ $routedNames->isEmpty() ? 'Apenas o próprio site' : $routedNames->join(', ') }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <button onclick="openModal('editSiteModal{{ $site->id }}')" class="p-1.5 text-muted-foreground hover:bg-muted rounded transition-colors" title="Editar {{ $site->name }}">
                                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </button>
                                <form action="{{ route('backend.sites.destroy', $site) }}" method="POST" class="inline" onsubmit="return confirm('Remover este site? Times/juizes vinculados perderao o vinculo.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="p-1.5 text-destructive hover:bg-destructive-soft rounded transition-colors" title="Excluir {{ $site->name }}">
                                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>

                    <!-- Modal Editar site -->
                    <div id="editSiteModal{{ $site->id }}" class="fixed inset-0 z-50 hidden">
                        <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('editSiteModal{{ $site->id }}')"></div>
                        <div class="fixed inset-0 flex items-center justify-center p-4">
                            <div class="bg-card rounded-xl shadow-xl border border-border w-full max-w-lg max-h-[90vh] overflow-y-auto">
                                <div class="flex flex-wrap items-center justify-between gap-3 p-6 border-b border-border">
                                    <h3 class="text-xl font-semibold text-foreground">Editar {{ $site->name }}</h3>
                                    <button onclick="closeModal('editSiteModal{{ $site->id }}')" class="p-2 hover:bg-muted rounded-lg transition-colors">
                                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                                <form action="{{ route('backend.sites.update', $site) }}" method="POST">
                                    @csrf
                                    @method('PUT')
                                    @include('backend.partials.site-fields', ['site' => $site, 'sites' => $sites, 'idPrefix' => 'edit' . $site->id])
                                    <div class="flex items-center justify-end gap-3 p-6 border-t border-border bg-muted/30">
                                        <button type="button" onclick="closeModal('editSiteModal{{ $site->id }}')" class="px-4 py-2 text-foreground hover:bg-muted rounded-lg transition-colors">Cancelar</button>
                                        <button type="submit" class="px-6 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary-hover transition-colors">Salvar</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-muted-foreground">Nenhum site cadastrado nesta competição ainda</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>

<!-- Modal Novo site -->
<div id="addSiteModal" class="fixed inset-0 z-50 hidden">
    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('addSiteModal')"></div>
    <div class="fixed inset-0 flex items-center justify-center p-4">
        <div class="bg-card rounded-xl shadow-xl border border-border w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div class="flex flex-wrap items-center justify-between gap-3 p-6 border-b border-border">
                <h3 class="text-xl font-semibold text-foreground">Novo site</h3>
                <button onclick="closeModal('addSiteModal')" class="p-2 hover:bg-muted rounded-lg transition-colors">
                    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <form action="{{ route('backend.sites.store') }}" method="POST">
                @csrf
                <input type="hidden" name="contest_id" value="{{ $contest?->id }}">
                @include('backend.partials.site-fields', ['site' => null, 'sites' => $sites, 'idPrefix' => 'new'])
                <div class="flex items-center justify-end gap-3 p-6 border-t border-border bg-muted/30">
                    <button type="button" onclick="closeModal('addSiteModal')" class="px-4 py-2 text-foreground hover:bg-muted rounded-lg transition-colors">Cancelar</button>
                    <button type="submit" class="px-6 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary-hover transition-colors">Criar</button>
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
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { document.querySelectorAll('[id$="Modal"], [id^="editSiteModal"]').forEach(modal => { if (!modal.classList.contains('hidden')) closeModal(modal.id); }); } });
</script>
@endsection
