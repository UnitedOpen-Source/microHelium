@extends('layouts.app')

@section('title', 'Editar Maratona')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center gap-4">
        <a href="{{ route('backend.configurations') }}" class="p-2 hover:bg-muted rounded-lg transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-foreground">Editar Maratona</h1>
            <p class="text-sm text-muted-foreground mt-1">{{ $hackathon->eventName }}</p>
        </div>
    </div>

    <form action="{{ route('backend.contest.update', $hackathon->hackathon_id) }}" method="POST" class="space-y-6">
        @csrf
        @method('PUT')

        <!-- Basic Info -->
        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-6 border-b border-border">
                <h2 class="text-lg font-semibold text-foreground">Informacoes Basicas</h2>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-foreground mb-2">Nome da Maratona *</label>
                    <input type="text" name="name" required
                        class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent"
                        value="{{ $hackathon->eventName }}">
                </div>
                <div>
                    <label class="block text-sm font-medium text-foreground mb-2">Descricao</label>
                    <textarea name="description" rows="3"
                        class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent resize-none">{{ $hackathon->description }}</textarea>
                </div>
            </div>
        </div>

        <!-- Schedule -->
        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-6 border-b border-border">
                <h2 class="text-lg font-semibold text-foreground">Agenda</h2>
            </div>
            <div class="p-6 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-2">Data e Hora de Inicio *</label>
                        <input type="datetime-local" name="start_time" required
                            class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent"
                            value="{{ $contest ? \Carbon\Carbon::parse($contest->start_time)->format('Y-m-d\TH:i') : \Carbon\Carbon::parse($hackathon->starts_at)->format('Y-m-d\TH:i') }}">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-2">Duracao (minutos) *</label>
                        <input type="number" name="duration" required min="30" max="10080"
                            class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent"
                            value="{{ $contest->duration ?? 300 }}">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-2">Penalidade (minutos)</label>
                        <input type="number" name="penalty" min="0" max="120"
                            class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent"
                            value="{{ $contest->penalty ?? 20 }}">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-foreground mb-2">Congelamento (minutos antes do fim)</label>
                        <input type="number" name="freeze_time" min="0"
                            class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent"
                            value="{{ $contest->freeze_time ?? 60 }}">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-foreground mb-2">Tamanho Maximo de Arquivo (KB)</label>
                    <input type="number" name="max_file_size" min="1" max="10240"
                        class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent"
                        value="{{ $contest->max_file_size ?? 100 }}">
                </div>
            </div>
        </div>

        <!-- Settings -->
        <div class="bg-card rounded-lg border border-border shadow-sm">
            <div class="p-6 border-b border-border">
                <h2 class="text-lg font-semibold text-foreground">Configuracoes</h2>
            </div>
            <div class="p-6 space-y-4">
                <div class="flex gap-4">
                    <label class="flex-1 flex items-center gap-3 p-3 bg-background border border-border rounded-lg cursor-pointer">
                        <input type="checkbox" name="is_active" value="1" class="w-5 h-5 rounded" {{ ($contest->is_active ?? false) ? 'checked' : '' }}>
                        <div>
                            <span class="text-sm font-medium text-foreground">Maratona Ativa</span>
                            <p class="text-xs text-muted-foreground">Permite submissoes e acesso dos participantes</p>
                        </div>
                    </label>
                    <label class="flex-1 flex items-center gap-3 p-3 bg-background border border-border rounded-lg cursor-pointer">
                        <input type="checkbox" name="is_public" value="1" class="w-5 h-5 rounded" {{ ($contest->is_public ?? false) ? 'checked' : '' }}>
                        <div>
                            <span class="text-sm font-medium text-foreground">Maratona Publica</span>
                            <p class="text-xs text-muted-foreground">Visivel para todos os usuarios</p>
                        </div>
                    </label>
                </div>
                <div>
                    <label class="block text-sm font-medium text-foreground mb-2">Chave de Acesso (opcional)</label>
                    <input type="text" name="unlock_key"
                        class="w-full px-4 py-3 bg-background border border-border rounded-lg text-foreground focus:ring-2 focus:ring-primary focus:border-transparent"
                        value="{{ $contest->unlock_key ?? '' }}"
                        placeholder="Deixe vazio para acesso livre">
                    <p class="text-xs text-muted-foreground mt-1">Se definida, participantes precisarao desta chave para entrar</p>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex items-center justify-between">
            <a href="{{ route('backend.configurations') }}" class="px-6 py-3 bg-muted text-muted-foreground rounded-lg hover:bg-muted/80 transition-colors">
                Cancelar
            </a>
            <button type="submit" class="px-6 py-3 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                Salvar Alteracoes
            </button>
        </div>
    </form>

    <!-- Danger Zone -->
    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
        <div class="p-6 border-b border-red-200 dark:border-red-800">
            <h2 class="text-lg font-semibold text-red-800 dark:text-red-300">Zona de Perigo</h2>
        </div>
        <div class="p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="font-medium text-red-800 dark:text-red-300">Excluir Maratona</p>
                    <p class="text-sm text-red-600 dark:text-red-400">Esta acao e irreversivel. Todos os dados serao perdidos.</p>
                </div>
                <form action="/backend/contest/{{ $hackathon->hackathon_id }}/delete" method="POST" onsubmit="return confirm('Tem certeza que deseja excluir esta maratona? Esta acao nao pode ser desfeita.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                        Excluir
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
