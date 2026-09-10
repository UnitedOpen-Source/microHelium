@extends('layouts.app')

@section('title', 'Gerenciar clarificações')

@section('description', 'Responda às dúvidas dos times com clareza e agilidade.')

@section('content')
<div class="space-y-6">
    <div class="bg-card rounded-lg border border-border shadow-sm">
        <div class="p-6 border-b border-border">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-foreground">Perguntas dos participantes</h2>
                    <p class="text-sm text-muted-foreground mt-1">Responda as duvidas dos participantes</p>
                </div>
            </div>
        </div>

        <div class="filter-toolbar" data-status-filter="admin-clarifications">
            <div class="flex flex-wrap gap-2" role="group" aria-label="Filtrar perguntas por situação">
                <button type="button" class="button-secondary filter-btn" data-status="all" aria-pressed="true">Todas</button>
                <button type="button" class="button-secondary filter-btn" data-status="pending" aria-pressed="false">Pendentes</button>
                <button type="button" class="button-secondary filter-btn" data-status="answered" aria-pressed="false">Respondidas</button>
            </div>
            <p role="status" class="text-sm text-muted-foreground"></p>
        </div>

        <div id="admin-clarifications" class="p-6 space-y-4">
            @forelse ($clarifications as $clarification)
            <div data-status="{{ $clarification->answered ? 'answered' : 'pending' }}" class="border border-border rounded-lg p-5 {{ !$clarification->answered ? 'bg-warning-soft border-warning/25 ' : 'bg-card' }}">
                <div class="flex flex-col lg:flex-row gap-4">
                    <div class="flex-1">
                        <div class="flex flex-wrap items-center gap-2 mb-3">
                            <span class="font-semibold text-foreground">{{ $clarification->team_name ?? 'Time #' . $clarification->team_id }}</span>
                            @if($clarification->problem)
                                <span class="px-2 py-1 text-xs font-medium bg-primary-soft text-primary rounded">{{ $clarification->problem }}</span>
                            @else
                                <span class="px-2 py-1 text-xs font-medium bg-muted text-muted-foreground rounded">Geral</span>
                            @endif
                            <span class="text-sm text-muted-foreground ml-auto">{{ $clarification->created_at }}</span>
                        </div>

                        <div class="bg-muted/50 rounded-lg p-4 mb-4">
                            <p class="text-sm font-medium text-muted-foreground mb-1">Pergunta:</p>
                            <p class="text-foreground">{{ $clarification->question }}</p>
                        </div>

                        @if($clarification->answered)
                            <div class="bg-success-soft rounded-lg p-4 border border-success/25">
                                <p class="text-sm font-medium text-success mb-1">Resposta:</p>
                                <p class="text-success">{{ $clarification->answer }}</p>
                            </div>
                        @else
                            <form action="/backend/clarifications/{{ $clarification->id }}/answer" method="POST">
                                @csrf
                                <div class="mb-3">
                                    <label for="answer-{{ $clarification->id }}" class="block text-sm font-medium mb-2">Sua resposta</label>
                                    <textarea id="answer-{{ $clarification->id }}" name="answer" rows="3" required
                                        class="w-full px-3 py-2 bg-background border border-border rounded-lg text-foreground placeholder-muted-foreground focus:ring-2 focus:ring-primary focus:border-transparent resize-none"
                                        placeholder="Digite sua resposta…"></textarea>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 bg-success text-success-foreground rounded-lg hover:bg-success-hover transition-colors">
                                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        Responder
                                    </button>
                                    <button type="button" onclick="this.form.answer.value='Sem comentários.'; this.form.answer.focus()"
                                        class="px-4 py-2 bg-muted text-foreground rounded-lg hover:bg-accent transition-colors">
                                        Sem comentários
                                    </button>
                                    <button type="button" onclick="this.form.answer.value='Leia o enunciado com atenção.'; this.form.answer.focus()"
                                        class="px-4 py-2 bg-muted text-foreground rounded-lg hover:bg-accent transition-colors">
                                        Leia o enunciado
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>

                    <div class="flex lg:flex-col items-center justify-center lg:w-32">
                        @if($clarification->answered)
                            <span class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium bg-success-soft text-success rounded-lg">
                                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                Respondida
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium bg-warning-soft text-warning rounded-lg">
                                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                Pendente
                            </span>
                        @endif
                    </div>
                </div>
            </div>
            @empty
            <div class="text-center py-12">
                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 mx-auto text-muted-foreground/50 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                </svg>
                <p class="text-muted-foreground">Nenhuma clarificacao recebida</p>
            </div>
            @endforelse
            <p data-filter-empty hidden class="text-center text-muted-foreground py-8">Nenhuma pergunta nesta situação. Selecione Todas para rever a lista.</p>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-card rounded-lg border border-border shadow-sm p-6 text-center">
            <div class="inline-flex items-center justify-center w-12 h-12 bg-info-soft rounded-lg mb-3">
                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-info" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                </svg>
            </div>
            <p class="text-sm text-muted-foreground">Total de Perguntas</p>
            <p class="text-3xl font-bold text-foreground mt-1">{{ $clarifications->count() }}</p>
        </div>
        <div class="bg-card rounded-lg border border-border shadow-sm p-6 text-center">
            <div class="inline-flex items-center justify-center w-12 h-12 bg-warning-soft rounded-lg mb-3">
                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-warning" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <p class="text-sm text-muted-foreground">Pendentes</p>
            <p class="text-3xl font-bold text-warning mt-1">{{ $clarifications->where('answered', false)->count() }}</p>
        </div>
        <div class="bg-card rounded-lg border border-border shadow-sm p-6 text-center">
            <div class="inline-flex items-center justify-center w-12 h-12 bg-success-soft rounded-lg mb-3">
                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <p class="text-sm text-muted-foreground">Respondidas</p>
            <p class="text-3xl font-bold text-success mt-1">{{ $clarifications->where('answered', true)->count() }}</p>
        </div>
    </div>
</div>
@endsection
